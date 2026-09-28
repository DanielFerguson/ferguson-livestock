<?php

namespace App\Http\Controllers;

use App\Enums\DropStatus;
use App\Models\Drop;
use Illuminate\Contracts\View\View;

/**
 * The order page: the form while a drop is open or coming up, the wait list between drops.
 */
class OrderPageController extends Controller
{
    public function __invoke(): View
    {
        $drop = Drop::featured();

        return view('pages.order', [
            'drop' => $drop !== null && $drop->status() !== DropStatus::Closed ? $drop : null,
        ]);
    }
}
