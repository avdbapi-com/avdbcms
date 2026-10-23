<?php

namespace Tests\Feature\Updater;

use App\Support\AppVersion;
use App\Support\Updater\ReleaseCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Feature\Updater\Support\UpdaterScenario;
use Tests\TestCase;

/**
 * Where an update comes from is fixed in code (F8 4, 15): the repository, the tag built from the version, the asset
 * names. Whoever can write the cache store (a shared Redis) or plan.json can at most name another version of this
 * repository: a kept answer that names another repository or a file name with "../" is never followed, and a plan
 * whose address or file name was changed is refused before anything is asked or written.
 */
class UpdateTrustTest extends TestCase
{
    use RefreshDatabase;
    use UpdaterScenario;

    private const FOREIGN = 'https://github.com/someone-else/not-avdbcms/releases/download/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSite();
    }

    protected function tearDown(): void
    {
        $this->removeSite();
        parent::tearDown();
    }

    public function test_a_poisoned_kept_answer_cannot_send_the_download_elsewhere(): void
    {
        $answer = (new ReleaseCheck())->get(10);
        $answer['release']['zip']['url'] = self::FOREIGN.'v'.$this->next.'/'.AppVersion::updateAsset($this->next);
        $answer['release']['zip']['name'] = '../../../../public/review-probe.php';
        $answer['release']['sha256'] = ['name' => 'checksum.sha256', 'url' => self::FOREIGN.'v'.$this->next.'/checksum.sha256'];
        $answer['release']['name'] = 'AVDBCMS '.$this->next.' (from the kept answer)';
        Cache::put(ReleaseCheck::CACHE_KEY, $answer, 3600);
        $asked = count($this->github->requests);

        $ready = $this->driveToSwap();

        $this->assertSame('swap', $ready['next']);
        $plan = $this->storedPlan();
        $this->assertSame(AppVersion::updateAsset($this->next), $plan['release']['zip']['name']);
        $this->assertSame(AppVersion::downloadBase($this->next).AppVersion::updateAsset($this->next), $plan['release']['zip']['url']);
        $this->assertSame(AppVersion::downloadBase($this->next).AppVersion::updateAsset($this->next).'.sha256', $plan['release']['sha256']['url']);
        $this->assertSame('AVDBCMS '.$this->next.' (from the kept answer)', $plan['release']['name'], 'what is only shown may come from the answer');

        foreach (array_slice($this->github->requests, $asked) as $request) {
            $this->assertStringNotContainsString('someone-else', $request['url']);
        }

        $this->assertFileDoesNotExist($this->box->root.'/public/review-probe.php');
        $this->assertFileExists($this->zipFile(), 'the zip is in the work folder under its own name');
    }

    public function test_a_plan_whose_download_address_was_changed_is_refused(): void
    {
        $this->assertTrue($this->updater()->start($this->admin)['ok']);
        $release = $this->storedPlan()['release'];
        $release['zip']['url'] = self::FOREIGN.'v'.$this->next.'/'.AppVersion::updateAsset($this->next);
        $this->changePlan(['release' => $release]);
        $asked = count($this->github->requests);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame(['failed', 'The download does not match the release. Try again later.'], [$answer['status'], $answer['message']]);

        foreach (array_slice($this->github->requests, $asked) as $request) {
            $this->assertStringNotContainsString('someone-else', $request['url']);
        }

        $this->assertSame([], $this->github->zipRanges(), 'the zip was not asked for anywhere');
        $this->assertSiteUnchanged();
    }

    public function test_a_plan_whose_zip_name_was_changed_writes_nothing_outside_its_folder(): void
    {
        $this->assertTrue($this->updater()->start($this->admin)['ok']);
        $release = $this->storedPlan()['release'];
        $release['zip']['name'] = '../../../../public/review-probe.php';
        $this->changePlan(['release' => $release]);

        $answer = $this->updater()->step($this->admin);

        $this->assertFalse($answer['ok']);
        $this->assertSame('failed', $answer['status']);
        $this->assertSame('The update package is not AVDBCMS '.$this->next.'. Nothing on your site was changed.', $answer['message']);
        $this->assertFileDoesNotExist($this->box->root.'/public/review-probe.php');
        $this->assertSame([], $this->github->zipRanges());
        $this->assertSiteUnchanged();
    }
}
