<?php

declare(strict_types=1);

use App\Enums\SmsStatus;
use App\Jobs\SendOrderConfirmationText;
use App\Models\SmsMessage;
use App\Sms\SmsGateway;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeSmsGateway;

/*
|--------------------------------------------------------------------------
| A waiting order text, rewritten by a night change while its job claims it
|--------------------------------------------------------------------------
|
| A second database connection holds the text's row the way a night change does while it rewrites the text. Postgres
| gives up waiting for it after a moment, so the test can tell a claim that waits from one that passes it by.
|
*/

it('waits for a text a night change is rewriting, then sends the new words', function (): void {
    $sms = FakeSmsGateway::swap();
    $message = SmsMessage::factory()->orderConfirmation(SmsStatus::Queued)->create();
    $sendIt = fn () => (new SendOrderConfirmationText($message->id, evenInQuietHours: true))->handle(app(SmsGateway::class));
    config(['database.connections.night_change' => config('database.connections.'.DB::getDefaultConnection())]);
    $nightChange = DB::connection('night_change');
    $nightChange->beginTransaction();
    $nightChange->table('sms_messages')->where('id', $message->id)->update(['body' => 'Ferguson Livestock: Hi Sam, we’ll deliver it on Sunday night.']);
    DB::statement("set lock_timeout = '250ms'");

    try {
        expect($sendIt)->toThrow(QueryException::class, 'lock timeout');
    } finally {
        $nightChange->commit();
        DB::statement('reset lock_timeout');
    }
    $sendIt();

    expect($sms->sent)->toBe([['to' => $message->to, 'body' => 'Ferguson Livestock: Hi Sam, we’ll deliver it on Sunday night.']]);
    expect($message->refresh()->status)->toBe(SmsStatus::Sent);
});
