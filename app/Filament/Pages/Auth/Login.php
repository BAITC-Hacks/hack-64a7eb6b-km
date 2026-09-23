<?php

namespace App\Filament\Pages\Auth;

use App\Actions\EnsureDemoAdmin;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class Login extends BaseLogin
{
    public function mount(): void
    {
        app(EnsureDemoAdmin::class)->handle();

        parent::mount();
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (! app(EnsureDemoAdmin::class)->enabled()) {
            return parent::getSubheading();
        }

        return new HtmlString(view('filament.auth.demo-credentials', [
            'email' => EnsureDemoAdmin::EMAIL,
            'password' => EnsureDemoAdmin::PASSWORD,
        ])->render());
    }
}
