<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Services;

use App\Core\Database\Page;
use App\Core\Database\PageRequest;
use App\Modules\Auth\Services\Reviewers;
use App\Modules\Reviews\Enums\ReviewStatus;
use App\Modules\Reviews\Models\Review;
use App\Modules\Users\Models\User;
use App\Modules\Users\Services\UserDirectory;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers' reviews as they are read: the moderation screen («نظرات», both panels and the website's admin side) —
 * every review of the shop, the newest first, by status (the pending ones the queue waiting on support, counted),
 * searched by the name it is signed with, its words, the customer who wrote it or its number; who decided it (Reviewers —
 * the owner's login «پشتیبانی» to anyone but the owner) — and the shop's website: the approved ones, the newest first,
 * the writers' own words and nothing of support's, with how many there are and their average rating.
 */
final class ReviewDirectory
{
    public function __construct(private readonly Reviewers $reviewers) {}

    /** One status (a tab) or all, searched — the newest first —; with the pending ones counted. */
    public function search(PageRequest $list): Page
    {
        $query = Review::query();
        $status = $list->enum('status', ReviewStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $list->search($query, self::applySearch(...));

        return Page::fetch($query->with('user')->orderByDesc('id'), $list, $this->present(...))
            ->with(['pending' => Review::query()->where('status', ReviewStatus::Pending->value)->count()]);
    }

    /** @return array<string, mixed> One row of the screen, its writer loaded with it. */
    public function present(Review $review): array
    {
        return [
            'id' => $review->id,
            'name' => $review->name,
            'rating' => $review->rating,
            'body' => $review->body,
            'context' => $review->context,
            'status' => $review->status->value,
            'customer' => $review->user === null ? null : UserDirectory::presentRef($review->user),
            'reviewer' => $this->reviewers->present($review->reviewer),
            'decided_at' => $review->decided_at?->toIso8601String(),
            'created_at' => $review->created_at->toIso8601String(),
            'actions' => Reviews::allowed($review),
        ];
    }

    /** What the shop's website shows: the approved reviews, the newest first, a page of them. */
    public function published(PageRequest $list): Page
    {
        return Page::fetch(self::approved()->orderByDesc('id'), $list, self::presentPublished(...));
    }

    /**
     * Every approved review, as one figure: how many, and their mean rating to two decimals (null while there is none) —
     * read off the index alone.
     *
     * @return array{count: int, average: float|null}
     */
    public function summary(): array
    {
        $row = self::approved()->toBase()->selectRaw('COUNT(*) AS reviews, AVG(rating) AS average')->first();
        $count = (int) ($row->reviews ?? 0);

        return ['count' => $count, 'average' => $count === 0 ? null : round((float) ($row->average ?? 0), 2)];
    }

    /**
     * A review as the website shows it: the writer's own words and choices — plain text, which the website escapes —,
     * nothing of who wrote it nor of support's.
     *
     * @return array<string, mixed>
     */
    private static function presentPublished(Review $review): array
    {
        return [
            'id' => $review->id,
            'name' => $review->name,
            'rating' => $review->rating,
            'body' => $review->body,
            'context' => $review->context,
            'avatar' => $review->avatar,
            'created_at' => $review->created_at->toIso8601String(),
        ];
    }

    /** @return Builder<Review> The shop's approved reviews. */
    private static function approved(): Builder
    {
        return Review::query()->where('status', ReviewStatus::Approved->value);
    }

    /**
     * By the customer who wrote it — name, handle, email, Telegram id, the way every list searches its customers —, the
     * name it is signed with, its words, or a bare number. («#12» is the review numbered 12 alone: PageRequest::search().)
     *
     * @param Builder<Review> $query
     */
    private static function applySearch(Builder $query, string $term, ?int $number): void
    {
        $users = User::idsMatching($term);

        $query->where(static function (Builder $q) use ($users, $term, $number): void {
            $q->whereIn('user_id', $users);
            Page::orWhereContains($q, 'name', $term);
            Page::orWhereContains($q, 'body', $term);
            if ($number !== null) {
                $q->orWhere('id', $number);
            }
        });
    }
}
