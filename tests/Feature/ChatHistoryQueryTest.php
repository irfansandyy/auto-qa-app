<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatHistoryQueryTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_history_queries_stay_constant_as_messages_grow(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['chat']);
        $session = ChatSession::factory()->for($user)->create(['title' => 'History']);

        for ($sequence = 1; $sequence <= 3; $sequence++) {
            ChatMessage::factory()->for($session)->create([
                'sequence' => $sequence,
                'role' => $sequence % 2 ? 'user' : 'assistant',
                'content' => 'First message '.$sequence,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/chat-sessions/'.$session->id)->assertOk();
        $firstCount = count(DB::getQueryLog());

        for ($sequence = 4; $sequence <= 33; $sequence++) {
            ChatMessage::factory()->for($session)->create([
                'sequence' => $sequence,
                'role' => $sequence % 2 ? 'user' : 'assistant',
                'content' => 'Later message '.$sequence,
            ]);
        }

        DB::flushQueryLog();
        $this->getJson('/api/chat-sessions/'.$session->id)->assertOk();
        $secondCount = count(DB::getQueryLog());

        $this->assertSame($firstCount, $secondCount);
        $this->assertLessThanOrEqual(5, $firstCount);
    }

    public function test_history_index_only_lists_owned_sessions(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        ChatSession::factory()->for($user)->withMessages(1, 1)->create(['title' => 'Owned history']);
        ChatSession::factory()->for($foreign)->create(['title' => 'Foreign history']);
        Sanctum::actingAs($user, ['chat']);

        $response = $this->getJson('/api/chat-sessions');

        $response->assertOk()
            ->assertJsonPath('data.0.title', 'Owned history')
            ->assertJsonPath('data.0.messages_count', 2)
            ->assertJsonMissing(['title' => 'Foreign history']);
    }

    public function test_history_page_is_read_only_and_owner_scoped(): void
    {
        $user = User::factory()->create();
        $foreign = User::factory()->create();
        $session = ChatSession::factory()->for($user)->withMessages(1, 1)->create(['title' => 'Visible transcript']);
        $foreignSession = ChatSession::factory()->for($foreign)->create(['title' => 'Private transcript']);

        $this->actingAs($user)->get('/chat-sessions/'.$session->id)
            ->assertOk()
            ->assertSee('Visible transcript')
            ->assertSee('QA question 0');

        $this->actingAs($user)->get('/chat-sessions/'.$foreignSession->id)->assertForbidden();
    }
}
