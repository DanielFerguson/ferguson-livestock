<?php

namespace App\Http\Controllers;

use App\Enums\DropStatus;
use App\Models\Drop;
use Illuminate\Contracts\View\View;

/**
 * The order page: the form while a drop is open or coming up, the wait list between drops (with the next
 * drop's date, once announced).
 */
class OrderPageController extends Controller
{
    public function __invoke(): View
    {
        $drop = Drop::featured();
        $drop = $drop !== null && $drop->status() !== DropStatus::Closed ? $drop : null;

        return view('pages.order', [
            'drop' => $drop,
            'announcedLabel' => $drop === null ? Drop::announced()?->announcedLabel() : null,
        ]);
    }
}
