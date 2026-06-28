<?php

declare(strict_types=1);

use Andre\AiPageBuilder\Flow\Contracts\AiInvoker;
use Andre\AiPageBuilder\Models\Page;
use Andre\AiPageBuilder\Tests\Fixtures\User;

function bindChatAi(string $reply): void
{
    app()->bind(AiInvoker::class, fn (): AiInvoker => new class($reply) implements AiInvoker
    {
        public function __construct(private string $reply) {}

        public function available(): bool
        {
            return true;
        }

        public function invoke(string $integration, array $args = [], array $messages = [], array $opts = []): string
        {
            return $this->reply;
        }
    });
}

it('threads a chat turn and returns a plan + summary reply', function (): void {
    bindChatAi(json_encode([
        'pages' => [['slug' => 'home', 'title' => 'Home', 'status' => 'published', 'html' => '<h1>Hi</h1>']],
    ]));

    $res = $this->actingAs(new User)->postJson('/ai-page-builder/ai-chat', [
        'messages' => [
            ['role' => 'user', 'content' => 'Build a home page'],
        ],
    ]);

    $res->assertOk()
        ->assertJsonPath('available', true)
        ->assertJsonPath('plan.pages.0.slug', 'home');

    expect($res->json('reply'))->toContain('1 page');
});

it('applies a plan from the chat', function (): void {
    $res = $this->actingAs(new User)->postJson('/ai-page-builder/ai-chat/apply', [
        'plan' => ['pages' => [['slug' => 'about', 'title' => 'About', 'status' => 'published', 'html' => '<p>x</p>']]],
    ]);

    $res->assertOk()->assertJsonPath('created.pages.0', 'about');
    expect(Page::query()->where('slug', 'about')->exists())->toBeTrue();
});

it('422s when applying an empty plan', function (): void {
    $this->actingAs(new User)
        ->postJson('/ai-page-builder/ai-chat/apply', ['plan' => []])
        ->assertStatus(422);
});
