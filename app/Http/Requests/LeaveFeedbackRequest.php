<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class LeaveFeedbackRequest extends FormRequest
{
    /**
     * Keep the feedback form's errors apart from the page's other forms.
     *
     * @var string
     */
    protected $errorBag = 'feedback';

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => 'Choose from 1 to 5 stars.',
            'rating.integer' => 'Choose from 1 to 5 stars.',
            'rating.between' => 'Choose from 1 to 5 stars.',
            'comment.max' => 'Please keep it under 2,000 characters.',
        ];
    }

    /**
     * Back to the feedback form at the bottom of the order page.
     */
    protected function getRedirectUrl(): string
    {
        $order = $this->route('customerOrder');

        return $order instanceof Order ? $order->pageUrl().'#feedback' : parent::getRedirectUrl();
    }
}
