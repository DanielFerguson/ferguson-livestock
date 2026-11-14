<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class ChangeDeliveryNightRequest extends FormRequest
{
    /**
     * Keep the night picker's errors apart from the page's other forms.
     *
     * @var string
     */
    protected $errorBag = 'night';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // Which nights are allowed is ChangeDeliveryNight's call, so its reasons reach the customer.
            'night' => ['required', 'string', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['night.required' => 'Choose a night.'];
    }

    /**
     * Back to the delivery part of the order page.
     */
    protected function getRedirectUrl(): string
    {
        $order = $this->route('customerOrder');

        return $order instanceof Order ? $order->pageUrl().'#delivery' : parent::getRedirectUrl();
    }
}
