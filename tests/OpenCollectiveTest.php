<?php

declare(strict_types=1);

namespace Tests;

use Newsletter\Draft;
use Newsletter\OpenCollectiveDrafts;
use Newsletter\OpenCollectivePublisher;
use Newsletter\StateStore;
use Newsletter\Writer;
use React\Promise\Deferred;

use function React\Promise\resolve;

final class OpenCollectiveTest extends TestCase
{
    private function response(): array
    {
        return ['data' => ['createUpdate' => ['id' => 'update-id', 'slug' => 'maintenance', 'publishedAt' => null, 'account' => ['slug' => 'discordphp']]]];
    }

    private function draft(): Draft
    {
        return new Draft('Maintenance', '<script>alert("x")</script> & thanks', [['title' => 'API <work>', 'body' => "One paragraph.\n\nSecond paragraph."]], 'Thank you');
    }

    public function testCreatesOnlyAnEscapedUnpublishedUpdate(): void
    {
        $publisher = new OpenCollectivePublisher(self::fakeHttp(['graphql/v2' => $this->response()], $log, $payloads), 'discordphp', 'test-secret');
        $result = self::settle($publisher->createDraft($this->draft()));
        self::assertSame('https://opencollective.com/discordphp/updates/maintenance', $result['url']);
        self::assertCount(1, $log);
        self::assertSame('test-secret', $payloads[0]['__headers']['Personal-Token']);
        self::assertStringNotContainsString('publishUpdate', $payloads[0]['query']);
        $update = $payloads[0]['variables']['update'];
        self::assertSame(['slug' => 'discordphp'], $update['account']);
        self::assertArrayNotHasKey('notificationAudience', $update);
        self::assertArrayNotHasKey('isDraft', $update);
        self::assertStringNotContainsString('<script>', $update['html']);
        self::assertStringContainsString('&lt;script&gt;', $update['html']);
        self::assertStringContainsString('<h2>API &lt;work&gt;</h2>', $update['html']);
        self::assertStringContainsString('<p>Second paragraph.</p>', $update['html']);
    }

    public function testRejectsPublishedOrWrongCollectiveResponsesWithoutLeakingErrors(): void
    {
        foreach ([['publishedAt' => '2026-10-11'], ['account' => ['slug' => 'other']], ['publishedAt' => false]] as $change) {
            $response = $this->response();
            $response['data']['createUpdate'] = array_replace($response['data']['createUpdate'], $change);
            $publisher = new OpenCollectivePublisher(self::fakeHttp(['graphql/v2' => $response]), 'discordphp', 'test-secret');
            try {
                self::settle($publisher->createDraft($this->draft()));
                self::fail('An unconfirmed draft was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('did not confirm', $error->getMessage());
                self::assertStringNotContainsString('test-secret', $error->getMessage());
            }
        }
    }

    public function testConfirmedDraftIsReusedAfterRestart(): void
    {
        $path = self::tempFile('collective-state');
        $state = new StateStore($path);
        $state->putEdition(['key' => '2026-10-11', 'status' => StateStore::STATUS_POSTED]);
        $publisher = new OpenCollectivePublisher(self::fakeHttp(['graphql/v2' => $this->response()], $log), 'discordphp', 'secret');
        $calls = 0;
        $generate = function () use (&$calls) {
            ++$calls;
            return resolve($this->draft());
        };
        $first = self::settle((new OpenCollectiveDrafts($state, $publisher, $generate))->create('2026-10-11'));
        $second = self::settle((new OpenCollectiveDrafts(new StateStore($path), $publisher, $generate))->create('2026-10-11'));
        self::assertSame($first, $second);
        self::assertSame('draft', $first['status']);
        self::assertSame(1, $calls);
        self::assertCount(1, $log);
        unlink($path);
    }

    public function testUncertainMutationIsNeverRetriedAfterRestart(): void
    {
        $path = self::tempFile('collective-uncertain');
        $state = new StateStore($path);
        $state->putEdition(['key' => 'day', 'status' => StateStore::STATUS_POSTED]);
        $publisher = new OpenCollectivePublisher(self::fakeHttp(['graphql/v2' => ['errors' => [['message' => 'secret diagnostics']]]], $log), 'discordphp', 'secret');
        foreach ([$state, new StateStore($path)] as $unused) {
            $service = new OpenCollectiveDrafts(new StateStore($path), $publisher, fn() => resolve($this->draft()));
            try {
                self::settle($service->create('day'));
                self::fail('Uncertain mutation was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringNotContainsString('secret diagnostics', $error->getMessage());
            }
        }
        self::assertCount(1, $log);
        self::assertSame('creation_started', (new StateStore($path))->edition('day')['collective_drafts']['discordphp']['status']);
        unlink($path);
    }

    public function testPendingEditionsNeverGenerateOrSend(): void
    {
        $path = self::tempFile('collective-pending');
        $state = new StateStore($path);
        $state->putEdition(['key' => 'day', 'status' => StateStore::STATUS_PENDING]);
        $publisher = new OpenCollectivePublisher(self::fakeHttp([], $log), 'discordphp', 'secret');
        $service = new OpenCollectiveDrafts($state, $publisher, function () {
            self::fail('Generator must not run.');
        });
        $this->expectExceptionMessage('Only a posted');
        try {
            self::settle($service->create('day'));
        } finally {
            self::assertSame([], $log);
            unlink($path);
        }
    }

    public function testApprovalIsRecheckedAfterGenerationAndConcurrentCallsAreBlocked(): void
    {
        $path = self::tempFile('collective-concurrent');
        $state = new StateStore($path);
        $state->putEdition(['key' => 'day', 'status' => StateStore::STATUS_POSTED]);
        $deferred = new Deferred();
        $publisher = new OpenCollectivePublisher(self::fakeHttp([], $log), 'discordphp', 'secret');
        $service = new OpenCollectiveDrafts($state, $publisher, fn() => $deferred->promise());
        $pending = $service->create('day');
        try {
            self::settle($service->create('day'));
            self::fail('Concurrent request accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already being prepared', $error->getMessage());
        }
        $state->putEdition(['key' => 'day', 'status' => StateStore::STATUS_REJECTED]);
        $deferred->resolve($this->draft());
        try {
            self::settle($pending);
            self::fail('Revoked approval accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('no longer approved', $error->getMessage());
        }
        self::assertSame([], $log);
        self::assertArrayNotHasKey('collective_drafts', $state->edition('day'));
        unlink($path);
    }

    public function testSupporterWriterUsesPublishedEvidenceAndReviewsSequentially(): void
    {
        $reply = "# API maintenance\nI merged an invite parsing fix. Thank you for supporting maintenance.";
        $writer = new Writer($this->fakeLlm([$reply, $reply]), 'Alex');
        $source = new Draft('Published edition', 'Merged invite parsing fix; no release recorded.', [['title' => 'Other activity', 'body' => 'Played a game and introduced Frontier Systems.']]);
        self::settle($writer->supporterUpdate($source, '2026-10-11'));
        self::assertCount(2, $this->llmMessages);
        self::assertStringContainsString('Do not invent donations', $this->llmMessages[0][0]['content']);
        self::assertStringContainsString('Leave out unrelated games', $this->llmMessages[0][0]['content']);
        self::assertStringContainsString($source->toMarkdown(), $this->prompts[0]);
        self::assertStringContainsString('no release recorded', $this->prompts[1]);
    }
}
