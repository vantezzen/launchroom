<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/**
 * Listeners are wired one way: event discovery reads the type of handle()'s
 * parameter. A listener without that parameter would never run, and one
 * also registered by hand would run twice.
 *
 * @return list<class-string>
 */
function listenerClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 3).'/app/Listeners')->name('*.php') as $file) {
        $classes[] = 'App\\Listeners\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
    }

    sort($classes);

    return $classes;
}

/**
 * @param  class-string  $listener
 * @return list<class-string>
 */
function listenedEvents(string $listener): array
{
    $type = (new ReflectionMethod($listener, 'handle')->getParameters()[0] ?? null)?->getType();
    $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];

    return array_values(array_map(static fn (ReflectionNamedType $named): string => $named->getName(), array_filter($types, static fn (mixed $named): bool => $named instanceof ReflectionNamedType && ! $named->isBuiltin())));
}

test('every listener takes the event it handles', function (string $listener): void {
    expect(listenedEvents($listener))->not->toBeEmpty();
})->with(listenerClasses());

test('every listener is registered exactly once for each event it handles', function (string $listener): void {
    $raw = Event::getRawListeners();

    foreach (listenedEvents($listener) as $event) {
        $registrations = array_filter($raw[$event] ?? [], static fn (mixed $entry): bool => is_string($entry) && Str::before($entry, '@') === $listener);

        expect($registrations)->toHaveCount(1, "{$listener} for {$event}");
    }
})->with(listenerClasses());
