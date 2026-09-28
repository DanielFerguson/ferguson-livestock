<?php

arch()->preset()->php();

// Filament names panel providers `*PanelProvider`.
arch()->preset()->laravel()->ignoring('App\Providers\Filament');

arch()->preset()->security();
