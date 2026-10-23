<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ErrorPageRenderingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('settings');
        Schema::dropIfExists('categories');

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('group')->default('general');
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('icon')->nullable();
            $table->string('background')->nullable();
            $table->boolean('in_menu')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Cache::flush();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('categories');
        Cache::flush();

        parent::tearDown();
    }

    public function test_not_found_error_page_renders_with_manual_schema_dependencies(): void
    {
        $this->get('/_missing-error-rendering-test')
            ->assertNotFound()
            ->assertSee('Go home');
    }
}
