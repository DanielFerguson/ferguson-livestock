<?php

use App\Enums\ProductType;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\DropItem;
use App\Models\Product;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Repeater;

use function Pest\Livewire\livewire;

beforeEach(function () {
    config(['shop.admin_email' => 'daniel@example.com']);
    $this->actingAs(User::factory()->withAppAuthentication()->create(['email' => 'daniel@example.com']));
    $this->undoRepeaterFake = Repeater::fake();
});

afterEach(fn () => ($this->undoRepeaterFake)());

it('adds a box with its contents', function () {
    livewire(CreateProduct::class)
        ->fillForm([
            'name' => '8kg Beef Box',
            'slug' => 'beef-box-8kg',
            'type' => ProductType::Box->value,
            'description' => 'A mid-size box.',
            'box_details' => [
                'weight_kg' => 8,
                'best_for' => 'Growing families',
                'freezer_guidance' => 'Allow about one and a half freezer drawers.',
                'contents' => [['line' => 'Approximately 1.2kg primary cuts'], ['line' => 'Approximately 1.5kg mince']],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $box = Product::where('slug', 'beef-box-8kg')->sole();

    expect($box->type)->toBe(ProductType::Box)
        ->and($box->box_details['contents'] ?? null)->toBe(['Approximately 1.2kg primary cuts', 'Approximately 1.5kg mince']);
});

it('locks the slug and type once a product exists', function () {
    $product = Product::factory()->create();

    livewire(EditProduct::class, ['record' => $product->getRouteKey()])
        ->assertFormFieldDisabled('slug')
        ->assertFormFieldDisabled('type');
});

it('keeps products that have been sold in a drop', function () {
    $sold = DropItem::factory()->create()->product;
    $unused = Product::factory()->create();

    livewire(EditProduct::class, ['record' => $sold->getRouteKey()])->assertActionHidden(DeleteAction::class);
    livewire(EditProduct::class, ['record' => $unused->getRouteKey()])->assertActionVisible(DeleteAction::class);
});
