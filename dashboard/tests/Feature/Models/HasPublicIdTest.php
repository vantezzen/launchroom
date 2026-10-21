<?php

declare(strict_types=1);

use App\Support\PublicId;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\PublicIdWidget;

beforeEach(function (): void {
    Schema::create('public_id_widgets', function (Blueprint $table): void {
        $table->id();
        $table->string('public_id')->unique();
        $table->string('name');
        $table->timestamps();
    });
});

test('generates a prefixed public id on create', function (): void {
    $widget = PublicIdWidget::query()->create(['name' => 'a']);

    expect($widget->public_id)->toStartWith('wdg_')
        ->and(PublicId::isValid($widget->public_id, 'wdg'))->toBeTrue()
        ->and($widget->getRouteKey())->toBe($widget->public_id);
});

test('keeps an explicitly provided public id', function (): void {
    $id = PublicId::generate('wdg');

    expect(PublicIdWidget::query()->create(['name' => 'a', 'public_id' => $id])->public_id)->toBe($id);
});

test('route model binding resolves by public id and 404s on malformed ids', function (): void {
    Route::middleware('web')->get('/_test/widgets/{widget}', fn (PublicIdWidget $widget): string => $widget->name);

    $widget = PublicIdWidget::query()->create(['name' => 'found']);

    $this->get("/_test/widgets/{$widget->public_id}")->assertOk()->assertSee('found');
    $this->get("/_test/widgets/{$widget->id}")->assertNotFound();
    $this->get('/_test/widgets/'.PublicId::generate('prj'))->assertNotFound();
    $this->get('/_test/widgets/'.PublicId::generate('wdg'))->assertNotFound();
});
