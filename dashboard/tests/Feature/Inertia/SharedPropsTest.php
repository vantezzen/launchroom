<?php

declare(strict_types=1);

use App\Data\Inertia\SharedPropsData;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Toast;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;

test('the signed-in user is shared as a whitelist, never the model', function (): void {
    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.user', [
                'name' => $user->name,
                'email' => $user->email,
                'emailVerified' => false,
                'isInstanceOwner' => false,
            ]),
        );
});

test('the shared props are exactly SharedPropsData', function (): void {
    $user = User::factory()->create();
    $request = Request::create('/');
    $request->setLaravelSession(resolve('session.store'));
    $request->setUserResolver(fn (): User => $user);

    $shared = resolve(HandleInertiaRequests::class)->share($request);
    unset($shared['errors']);
    $resolved = array_map(fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value, $shared);

    $properties = array_map(
        fn (ReflectionParameter $parameter): string => $parameter->getName(),
        new ReflectionMethod(SharedPropsData::class, '__construct')->getParameters(),
    );

    expect(array_keys($resolved))->toEqualCanonicalizing($properties)
        ->and(SharedPropsData::from($resolved))->toBeInstanceOf(SharedPropsData::class);
});

test('toasts are flashed as type and message', function (): void {
    Toast::warning('Careful.');

    expect(session('inertia.flash_data.toast'))->toBe(['type' => 'warning', 'message' => 'Careful.']);
});
