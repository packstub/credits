<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Packstub\Credits\Models\Wallet;
use Packstub\Credits\Tests\Fixtures\Models\Project;

it('creates the wallet table with uuid holder columns', function () {
    expect(Schema::getColumnType('credit_wallets', 'holder_id'))->toBeIn(['uuid', 'varchar', 'char', 'string']);
});

it('keeps credits for a holder keyed by a uuid', function () {
    $project = Project::query()->create(['name' => 'Apollo']);
    $other = Project::query()->create(['name' => 'Gemini']);

    expect(Str::isUuid($project->getKey()))->toBeTrue();

    $project->credits()->grant(100, 'Top-up');
    $project->credits()->spend(30);
    $other->credits()->grant(5);
    $project->credits()->transfer($other->credits(), 10);

    expect($project->credits()->available())->toBe(60)
        ->and($other->credits()->available())->toBe(15)
        ->and(Project::query()->find($project->getKey())->credits()->available())->toBe(60)
        ->and(Wallet::query()->where('holder_id', $project->getKey())->count())->toBe(1)
        ->and($project->creditWallets()->first()->holder->is($project))->toBeTrue();

    $this->artisan('credits:audit')->assertSuccessful();
});

it('expires lots of idle uuid holders from the command', function () {
    $project = Project::query()->create(['name' => 'Apollo']);
    $project->credits()->grant(100, expiresAt: now()->addDay());

    $this->travel(2)->days();

    $this->artisan('credits:expire')->expectsOutputToContain('1 wallet caught up.');
    expect($project->credits()->available())->toBe(0);
});
