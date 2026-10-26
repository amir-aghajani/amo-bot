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
use Illuminate\Database\Eloquent\Collection;

/**
 * The reviews as they are read: the moderation screen («نظرات», both panels and the website's admin API) — every review,
 * newest first, by status (pending ones are support's queue, counted) and search, with who decided each (Reviewers: the
 * owner's login reads «پشتیبانی» to anyone but the owner; one of the shop's admins with their name too) — and the
 * website: the approved ones, newest first, with their count and average rating, showing nothing of who wrote them or
 * of support.
 */
final class ReviewDirectory
{
    public function __construct(private readonly Reviewers $reviewers) {}

    /** A page of reviews by status (a tab) and search, newest first; with the pending ones counted. */
    public function search(PageRequest $list): Page
    {
        $query = Review::query();
        $status = $list->enum('status', ReviewStatus::class);
        if ($status !== null) {
            $query->where('status', $status->value);
        }
        $list->search($query, self::applySearch(...));

        return Page::fetchTogether($query->with('user')->orderByDesc('id'), $list, $this->presentAll(...))
            ->with(['pending' => Review::query()->where('status', ReviewStatus::Pending->value)->count()]);
    }

    /** @return array<string, mixed> One row of the screen, its writer loaded with it. */
    public function present(Review $review): array
    {
        return $this->presentAll(new Collection([$review]))[0];
    }

    /**
     * Rows of the screen, the names of the shop's admins who decided them read once for them all (Reviewers::names()).
     *
     * @param Collection<int, Review> $reviews
     * @return list<array<string, mixed>>
     */
    private function presentAll(Collection $reviews): array
    {
        $names = $this->reviewers->names($reviews->pluck('reviewer'));

        return array_values($reviews->map(fn(Review $review): array => [
            'id' => $review->id,
            'name' => $review->name,
            'rating' => $review->rating,
            'body' => $review->body,
            'context' => $review->context,
            'status' => $review->status->value,
            'customer' => $review->user === null ? null : UserDirectory::presentRef($review->user),
            'reviewer' => $this->reviewers->present($review->reviewer),
            'reviewer_name' => $names[$review->reviewer ?? ''] ?? null,
            'decided_at' => $review->decided_at?->toIso8601String(),
            'created_at' => $review->created_at?->toIso8601String(),
            'actions' => Reviews::allowed($review),
        ])->all());
    }

    /** What the shop's website shows: the approved reviews, the newest first, a page of them. */
    public function published(PageRequest $list): Page
    {
        return Page::fetch(self::approved()->orderByDesc('id'), $list, self::presentPublished(...));
    }

    /**
     * How many reviews are approved and their mean rating to two decimals (null while none) — read off the index alone.
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
     * A review as the website shows it: the writer's own words and choices (plain text the website escapes), nothing of
     * who wrote it or of support.
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
            'created_at' => $review->created_at?->toIso8601String(),
        ];
    }

    /** @return Builder<Review> The shop's approved reviews. */
    private static function approved(): Builder
    {
        return Review::query()->where('status', ReviewStatus::Approved->value);
    }

    /**
     * Search by the customer who wrote it (User::idsMatching()), the name it is signed with, its text, or a bare number.
     * «#12» alone is review 12: PageRequest::search().
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
