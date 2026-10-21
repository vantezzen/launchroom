<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;

function horizonRequestFor(?User $user): Request
{
    $request = Request::create('/horizon');
    $request->setUserResolver(fn (): ?User => $user);

    return $request;
}

test('guests are redirected to login before reaching Horizon', function (): void {
    $this->get('/horizon')->assertRedirect(route('login'));
});

test('outside local, only instance owners may view Horizon', function (): void {
    $owner = User::factory()->instanceOwner()->create();
    $other = User::factory()->create();

    expect(app()->environment('local'))->toBeFalse()
        ->and(Horizon::check(horizonRequestFor($owner)))->toBeTrue()
        ->and(Horizon::check(horizonRequestFor($other)))->toBeFalse()
        ->and(Horizon::check(horizonRequestFor(null)))->toBeFalse();
});
