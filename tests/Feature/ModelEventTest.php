<?php

declare(strict_types=1);

namespace Kayra\Tests\Feature;

use Kayra\Database\Connection;
use Kayra\Database\Events\Created;
use Kayra\Database\Events\Creating;
use Kayra\Database\Events\Deleted;
use Kayra\Database\Events\Deleting;
use Kayra\Database\Events\ModelEvent;
use Kayra\Database\Events\Retrieved;
use Kayra\Database\Events\Saved;
use Kayra\Database\Events\Saving;
use Kayra\Database\Events\Updated;
use Kayra\Database\Events\Updating;
use Kayra\Database\Model;
use Kayra\Events\Dispatcher;
use Kayra\Tests\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;

/**
 * @property int|null $id
 * @property string   $title
 * @property string   $body
 */
final class EventedPost extends Model
{
    protected string $table = 'evented_posts';

    /** @var list<string> */
    protected array $fillable = ['title', 'body'];

    protected bool $timestamps = false;
}

/**
 * What a model announces as it is read, written and removed.
 */
#[RequiresPhpExtension('pdo_sqlite')]
final class ModelEventTest extends TestCase
{
    private Dispatcher $events;

    /** @var list<string> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->config()->set('database.default', 'sqlite');
        $this->app->config()->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
        ]);

        $this->app->get(Connection::class)->statement(
            'CREATE TABLE evented_posts (id INTEGER PRIMARY KEY, title TEXT, body TEXT)',
        );

        $this->events = $this->app->get(Dispatcher::class);
        Model::setEventDispatcher($this->events);

        $this->fired = [];
        $this->events->listen(ModelEvent::class, function (ModelEvent $e): void {
            $this->fired[] = substr(strrchr($e::class, '\\') ?: $e::class, 1);
        });
    }

    protected function tearDown(): void
    {
        Model::setEventDispatcher(null);

        parent::tearDown();
    }

    private function makePost(string $title = 'First'): EventedPost
    {
        $post = new EventedPost(['title' => $title, 'body' => 'body']);
        $post->save();

        return $post;
    }

    /* --------------------------------------------------------------------
     | Order
     * -------------------------------------------------------------------- */

    #[Test]
    public function an_insert_fires_saving_creating_created_saved_in_that_order(): void
    {
        $this->makePost();

        $this->assertSame(['Saving', 'Creating', 'Created', 'Saved'], $this->fired);
    }

    #[Test]
    public function an_update_fires_the_update_pair_not_the_create_pair(): void
    {
        $post = $this->makePost();
        $this->fired = [];

        $post->title = 'Renamed';
        $post->save();

        $this->assertSame(['Saving', 'Updating', 'Updated', 'Saved'], $this->fired);
    }

    #[Test]
    public function saving_a_model_with_no_changes_fires_no_update_events(): void
    {
        // "updating" should mean something is being updated. An UPDATE with an
        // empty SET is not issued, so nor is the event.
        $post = $this->makePost();
        $this->fired = [];

        $post->save();

        $this->assertSame(['Saving', 'Saved'], $this->fired);
    }

    #[Test]
    public function a_delete_fires_deleting_then_deleted(): void
    {
        $post = $this->makePost();
        $this->fired = [];

        $post->delete();

        $this->assertSame(['Deleting', 'Deleted'], $this->fired);
    }

    #[Test]
    public function reading_a_row_fires_retrieved(): void
    {
        $this->makePost();
        $this->fired = [];

        EventedPost::query()->get();

        $this->assertSame(['Retrieved'], $this->fired);
    }

    /* --------------------------------------------------------------------
     | Cancelling
     * -------------------------------------------------------------------- */

    #[Test]
    public function cancelling_creating_abandons_the_insert(): void
    {
        $this->events->listen(Creating::class, static fn (Creating $e) => $e->cancel());

        $post = new EventedPost(['title' => 'Never', 'body' => 'x']);

        $this->assertFalse($post->save());
        $this->assertSame(0, EventedPost::query()->count());
        $this->assertFalse($post->exists());
    }

    #[Test]
    public function cancelling_saving_abandons_both_paths(): void
    {
        $post = $this->makePost();

        $this->events->listen(Saving::class, static fn (Saving $e) => $e->cancel());

        $post->title = 'Renamed';

        $this->assertFalse($post->save());
        $this->assertSame('First', EventedPost::findOrFail($post->id)->title);
    }

    #[Test]
    public function cancelling_updating_leaves_the_row_alone(): void
    {
        $post = $this->makePost();
        $this->events->listen(Updating::class, static fn (Updating $e) => $e->cancel());

        $post->title = 'Renamed';

        $this->assertFalse($post->save());
        $this->assertSame('First', EventedPost::findOrFail($post->id)->title);
    }

    #[Test]
    public function cancelling_deleting_keeps_the_row(): void
    {
        $post = $this->makePost();
        $this->events->listen(Deleting::class, static fn (Deleting $e) => $e->cancel());

        $this->assertFalse($post->delete());
        $this->assertSame(1, EventedPost::query()->count());
        $this->assertTrue($post->exists());
    }

    #[Test]
    public function a_cancelled_before_event_suppresses_its_after_event(): void
    {
        $this->events->listen(Creating::class, static fn (Creating $e) => $e->cancel());
        $this->fired = [];

        (new EventedPost(['title' => 'Never', 'body' => 'x']))->save();

        $this->assertNotContains('Created', $this->fired);
        $this->assertNotContains('Saved', $this->fired);
    }

    /* --------------------------------------------------------------------
     | What a listener can see and change
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_creating_listener_can_still_change_the_attributes(): void
    {
        $this->events->listen(Creating::class, static function (Creating $e): void {
            // The event carries a Model. A listener that wants a specific one
            // asks -- which is also what stops it firing on unrelated tables.
            if ($e->model instanceof EventedPost) {
                $e->model->title = 'Rewritten by a listener';
            }
        });

        $post = $this->makePost();

        $this->assertSame('Rewritten by a listener', EventedPost::findOrFail($post->id)->title);
    }

    #[Test]
    public function an_updating_listener_can_add_to_the_change_set(): void
    {
        // The dirty set is re-read after the event, or a listener's write would
        // be silently dropped from the UPDATE.
        $post = $this->makePost();

        $this->events->listen(Updating::class, static function (Updating $e): void {
            if ($e->model instanceof EventedPost) {
                $e->model->body = 'stamped';
            }
        });

        $post->title = 'Renamed';
        $post->save();

        $fresh = EventedPost::findOrFail($post->id);

        $this->assertSame('Renamed', $fresh->title);
        $this->assertSame('stamped', $fresh->body);
    }

    #[Test]
    public function created_sees_the_primary_key(): void
    {
        $seen = null;
        $this->events->listen(Created::class, static function (Created $e) use (&$seen): void {
            $seen = $e->model->getKey();
        });

        $post = $this->makePost();

        $this->assertNotNull($seen);
        $this->assertSame($post->getKey(), $seen);
    }

    #[Test]
    public function deleted_can_still_read_what_was_removed(): void
    {
        // The attributes are deliberately left populated: a listener writing an
        // audit row has nowhere else to read them from.
        $post = $this->makePost('Doomed');

        $seen = null;
        $this->events->listen(Deleted::class, static function (Deleted $e) use (&$seen): void {
            $seen = $e->model->getAttributes()['title'] ?? null;
        });

        $post->delete();

        $this->assertSame('Doomed', $seen);
    }

    /* --------------------------------------------------------------------
     | Turning it off
     * -------------------------------------------------------------------- */

    #[Test]
    public function without_events_silences_the_block_and_restores_afterwards(): void
    {
        Model::withoutEvents(function (): void {
            $this->makePost('Quiet');
        });

        $this->assertSame([], $this->fired, 'nothing should have fired inside the block');

        $this->makePost('Loud');

        $this->assertContains('Created', $this->fired, 'events must come back after the block');
    }

    #[Test]
    public function without_events_restores_the_dispatcher_even_when_the_callback_throws(): void
    {
        try {
            Model::withoutEvents(static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($this->events, Model::getEventDispatcher());
    }

    #[Test]
    public function a_model_with_no_dispatcher_does_no_event_work(): void
    {
        Model::setEventDispatcher(null);

        $post = $this->makePost();

        $this->assertSame([], $this->fired);
        $this->assertTrue($post->exists());
    }

    /* --------------------------------------------------------------------
     | Specific listeners
     * -------------------------------------------------------------------- */

    #[Test]
    public function a_listener_can_target_one_event_instead_of_all_of_them(): void
    {
        $updates = 0;
        $this->events->listen(Updated::class, static function () use (&$updates): void {
            $updates++;
        });

        $post = $this->makePost();
        $this->assertSame(0, $updates, 'an insert is not an update');

        $post->title = 'Renamed';
        $post->save();

        $this->assertSame(1, $updates);
    }
}
