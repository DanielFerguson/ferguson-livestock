<?php

declare(strict_types=1);

use App\Actions\Drops\SetDropItem;
use App\Actions\RunDropPreflight;
use App\Exceptions\CannotChangeDrop;
use App\Models\Bundle;
use App\Models\Drop;
use App\Models\DropItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Payments\PaymentGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakePaymentGateway;

beforeEach(function (): void {
    $this->stripe = new FakePaymentGateway;
    app()->instance(PaymentGateway::class, $this->stripe);
    $this->mince = Product::factory()->create(['name' => '500g Mince']);
    $this->pack = Product::factory()->bundleOf($this->mince, 10)->create(['name' => '5kg Mince Pack']);
});

describe('what can be a bundle', function (): void {
    it('accepts a normal bundle', function (): void {
        $other = Product::factory()->create();

        expect(Bundle::problemsFor($other, $this->mince, 5))->toBe([]);
    });

    it('refuses nonsense', function (): void {
        $plain = Product::factory()->create();
        $fee = Product::factory()->delivery()->create();

        expect(Bundle::problemsFor($this->mince, $this->mince, 2))->not->toBe([])
            ->and(Bundle::problemsFor($plain, $fee, 2))->not->toBe([])
            ->and(Bundle::problemsFor($fee, $this->mince, 2))->not->toBe([])
            ->and(Bundle::problemsFor($plain, $this->pack, 2))->not->toBe([])
            ->and(Bundle::problemsFor($this->mince, $plain, 2))->not->toBe([])
            ->and(Bundle::problemsFor($plain, $this->mince, 0))->not->toBe([]);
    });

    it('refuses a product already on a drop with its own stock', function (): void {
        $other = Product::factory()->create(['name' => 'Bones']);
        DropItem::factory()->for(Drop::factory()->draft()->create())->for($other)->create(['quantity' => 5]);

        expect(Bundle::problemsFor($other, $this->mince, 2))->not->toBe([]);
    });

    it('is enforced by the database too', function (): void {
        expect(fn () => Bundle::query()->create(['product_id' => $this->mince->id, 'component_product_id' => $this->mince->id, 'units' => 2]))->toThrow(QueryException::class);
        expect(fn () => Bundle::query()->create(['product_id' => Product::factory()->create()->id, 'component_product_id' => $this->mince->id, 'units' => 0]))->toThrow(QueryException::class);
        expect(fn () => Bundle::query()->create(['product_id' => $this->pack->id, 'component_product_id' => $this->mince->id, 'units' => 3]))->toThrow(QueryException::class);
    });
});

describe('putting a bundle on a drop', function (): void {
    it('needs the component on the drop first', function (): void {
        $drop = Drop::factory()->draft()->create();
        $this->stripe->addPrice('price_pack', 11000);

        expect(fn () => app(SetDropItem::class)($drop, $this->pack, 'price_pack', 11000))->toThrow(CannotChangeDrop::class, 'Add 500g Mince');

        DropItem::factory()->for($drop)->for($this->mince)->create(['quantity' => 40]);
        $item = app(SetDropItem::class)($drop, $this->pack, 'price_pack', 11000);

        expect($item->quantity)->toBeNull()->and($item->price)->toBe(11000);
    });

    it('has no quantity of its own', function (): void {
        $drop = Drop::factory()->draft()->create();
        DropItem::factory()->for($drop)->for($this->mince)->create(['quantity' => 40]);
        $this->stripe->addPrice('price_pack', 11000);

        expect(fn () => app(SetDropItem::class)($drop, $this->pack, 'price_pack', 11000, 5))->toThrow(CannotChangeDrop::class, 'sold from 500g Mince stock');
    });

    it('fails the pre-flight check when the component is missing or has no stock', function (): void {
        $drop = Drop::factory()->draft()->create();
        DropItem::factory()->for($drop)->for($this->pack)->withoutStock()->create(['stripe_price_id' => 'price_pack', 'price' => 11000]);
        $this->stripe->addPrice('price_pack', 11000);

        $report = app(RunDropPreflight::class)($drop);

        expect(implode(' ', $report['problems']))->toContain('5kg Mince Pack is sold from 500g Mince stock');
    });
});

describe('taking things off a drop', function (): void {
    it('keeps the component while a bundle on the drop is sold from it', function (): void {
        $drop = Drop::factory()->draft()->create();
        $component = DropItem::factory()->for($drop)->for($this->mince)->create(['quantity' => 40]);
        DropItem::factory()->for($drop)->for($this->pack)->withoutStock()->create();

        expect($component->whyItCantBeRemoved())->toContain('5kg Mince Pack is sold from its stock');
    });

    it('keeps a component that bundle orders took stock from, with a message instead of a database error', function (): void {
        $drop = Drop::factory()->open()->create();
        $component = DropItem::factory()->for($drop)->for($this->mince)->create(['quantity' => 40]);
        $packItem = DropItem::factory()->for($drop)->for($this->pack)->withoutStock()->create();
        $order = Order::factory()->for($drop)->create();
        OrderItem::factory()->for($order)->create(['drop_item_id' => $packItem->id, 'product_id' => $this->pack->id, 'stock_drop_item_id' => $component->id, 'stock_units' => 10]);

        expect($component->refresh()->whyItCantBeRemoved())->toContain('past orders');
    });
});

it('deletes a bundle row with its product', function (): void {
    $this->pack->delete();

    expect(Bundle::query()->count())->toBe(0);
});

it('keeps a component from being deleted while a bundle uses it', function (): void {
    expect(fn () => $this->mince->delete())->toThrow(QueryException::class);
});

describe('the migration', function (): void {
    it('turns existing borrowed-stock products into bundles, leaving the old columns alone', function (): void {
        $path = 'database/migrations/2026_10_29_010000_create_bundles_table.php';
        Artisan::call('migrate:rollback', ['--path' => $path, '--force' => true]);

        $five = Product::factory()->box()->create();
        $ten = Product::factory()->box(10)->create();
        $plain = Product::factory()->create();
        DB::table('products')->where('id', $ten->id)->update(['stock_product_id' => $five->id, 'stock_units' => 2]);

        Artisan::call('migrate', ['--path' => $path, '--force' => true]);

        expect(Bundle::query()->count())->toBe(1);

        $bundle = Bundle::query()->sole();

        expect($bundle->product_id)->toBe($ten->id)
            ->and($bundle->component_product_id)->toBe($five->id)
            ->and($bundle->units)->toBe(2)
            ->and(DB::table('products')->where('id', $ten->id)->value('stock_product_id'))->toBe($five->id)
            ->and(Bundle::query()->where('product_id', $plain->id)->exists())->toBeFalse();
    });
});
