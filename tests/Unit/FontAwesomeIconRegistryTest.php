<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Support\FontAwesomeIconRegistry;
use App\Support\NavigationSettings;
use PHPUnit\Framework\TestCase;

class FontAwesomeIconRegistryTest extends TestCase
{
    public function test_registry_contains_the_installed_solid_glyphs_used_by_public_ui(): void
    {
        $this->assertCount(1402, FontAwesomeIconRegistry::all());

        foreach (['folder', 'video', 'film', 'user', 'mobile-screen', 'wand-magic-sparkles'] as $icon) {
            $this->assertTrue(FontAwesomeIconRegistry::isSupported($icon), $icon);
        }
    }

    public function test_unknown_values_are_normalized_to_a_safe_solid_fallback(): void
    {
        $this->assertSame('folder', FontAwesomeIconRegistry::normalize('not-a-real-font-awesome-glyph'));
        $this->assertSame('folder', FontAwesomeIconRegistry::normalize('fa-video'));
        $this->assertSame('folder', FontAwesomeIconRegistry::normalize('<i class="fa-video">'));
        $this->assertSame('folder', FontAwesomeIconRegistry::normalize(null));
        $this->assertSame('video', FontAwesomeIconRegistry::normalize(' VIDEO '));
    }

    public function test_free_aliases_are_canonicalized_but_are_not_canonical_registry_names(): void
    {
        $this->assertFalse(FontAwesomeIconRegistry::isSupported('automobile'));
        $this->assertFalse(FontAwesomeIconRegistry::isSupported('female'));
        $this->assertSame('car', FontAwesomeIconRegistry::normalize('automobile'));
        $this->assertSame('person-dress', FontAwesomeIconRegistry::normalize('female'));
    }

    public function test_category_icon_name_uses_the_registry_and_never_exposes_an_unknown_class(): void
    {
        $category = new Category;
        $category->forceFill(['icon' => 'not-a-real-font-awesome-glyph']);
        $this->assertSame(FontAwesomeIconRegistry::FALLBACK, $category->icon_name);

        $category->forceFill(['icon' => 'video']);
        $this->assertSame('video', $category->icon_name);
    }

    public function test_custom_sidebar_icons_are_normalized_to_installed_solid_glyphs(): void
    {
        $this->assertSame('video', NavigationSettings::cleanIcon('video'));
        $this->assertSame('folder', NavigationSettings::cleanIcon('fa-does-not-exist'));
        $this->assertSame('', NavigationSettings::cleanIcon(''));
    }
}
