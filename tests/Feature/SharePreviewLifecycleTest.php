<?php

namespace Tests\Feature;

use App\Models\Video;
use DOMDocument;
use DOMXPath;
use Tests\TestCase;

class SharePreviewLifecycleTest extends TestCase
{
    public function test_share_modal_keeps_preview_inert_and_copied_embed_code_unchanged(): void
    {
        $video = (new Video())->forceFill([
            'id' => 1,
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'title' => 'Share lifecycle fixture',
            'slug' => 'share-lifecycle-fixture',
        ])->setRelation('user', null)->setRelation('category', null)->setRelation('thumbnail', null)
            ->setRelation('translations', collect());

        foreach (['en', 'ar'] as $locale) {
            $this->app->setLocale($locale);

            foreach (['modal_glassmorphic_center', 'modal_fullscreen_cinema', 'modal_slide_sheet', 'modal_brutalist_sharp'] as $style) {
                config(['site.modal_presentation_style' => $style]);
                $html = view('modals.share', ['video' => $video])->render();
                $document = new DOMDocument();
                $previous = libxml_use_internal_errors(true);
                try {
                    $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previous);
                }
                $xpath = new DOMXPath($document);
                $this->assertSame(0, $xpath->query('//iframe[not(ancestor::template)]')->length,
                    "{$locale}/{$style}: a closed share modal must not start another player");
                $preview = $xpath->query('//template[@x-if="modalOpen && embed"]/iframe');
                $this->assertSame(1, $preview->length);
                $this->assertSame(route('video.embed', $video), $preview->item(0)->getAttribute('src'));
                $this->assertSame($style, $xpath->query('//*[@id="share"]')->item(0)->getAttribute('data-modal-style'));

                $expectedEmbed = '<iframe width="560" height="315" src="'.route('video.embed', $video).'" title="'.config('app.name').' video player" frameborder="0" allow="accelerometer; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>';
                $this->assertSame($expectedEmbed, $xpath->query('//*[@id="embed_code"]')->item(0)->textContent);
                $this->assertSame($expectedEmbed, $xpath->query('//*[@data-link]')->item(0)->getAttribute('data-link'));
                $this->assertSame(7, $xpath->query('//*[@id="share"]//div[@x-show="!embed"]//a[@href]')->length);

                // Optional isolated browser-fixture export; ordinary suite runs write no fixture.
                $fixtureDirectory = getenv('AVD_SHARE_QA_FIXTURE_DIR');
                if ($fixtureDirectory && $style === 'modal_glassmorphic_center') {
                    $this->assertDirectoryExists($fixtureDirectory);
                    file_put_contents($fixtureDirectory.'/share-'.$locale.'.html', $html);
                }
            }
        }
    }
}
