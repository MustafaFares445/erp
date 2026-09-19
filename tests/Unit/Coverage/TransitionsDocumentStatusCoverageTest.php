<?php

declare(strict_types=1);

use App\Exceptions\Domain\IllegalStatusTransition;
use App\Models\Concerns\TransitionsDocumentStatus;
use Illuminate\Database\Eloquent\Model;

if (! enum_exists('CoverageTransitionStatus')) {
    enum CoverageTransitionStatus: string
    {
        case Draft = 'draft';
        case Done = 'done';

        public function canTransitionTo(self $target): bool
        {
            return $this === self::Draft && $target === self::Done;
        }
    }
}

if (! enum_exists('CoverageNoTransitionStatus')) {
    enum CoverageNoTransitionStatus: string
    {
        case Draft = 'draft';
    }
}

if (! enum_exists('CoverageOtherTransitionStatus')) {
    enum CoverageOtherTransitionStatus: string
    {
        case Done = 'done';
    }
}
if (! class_exists('CoverageTransitionModel')) {
    final class CoverageTransitionModel extends Model
    {
        use TransitionsDocumentStatus;

        #[Override]
        protected function casts(): array
        {
            return ['status' => CoverageTransitionStatus::class];
        }
    }
}

if (! class_exists('CoverageNoTransitionModel')) {
    final class CoverageNoTransitionModel extends Model
    {
        use TransitionsDocumentStatus;

        #[Override]
        protected function casts(): array
        {
            return ['status' => CoverageNoTransitionStatus::class];
        }
    }
}

if (! class_exists('CoverageRawStatusModel')) {
    final class CoverageRawStatusModel extends Model
    {
        use TransitionsDocumentStatus;
    }
}
it('covers every document status transition guard branch', function (): void {
    $raw = new CoverageRawStatusModel;
    $raw->setAttribute('status', 'draft');

    expect(fn () => $raw->assertCanTransitionTo(CoverageTransitionStatus::Done))
        ->toThrow(LogicException::class);

    $wrongEnum = new CoverageTransitionModel;
    $wrongEnum->setAttribute('status', CoverageTransitionStatus::Draft);

    expect(fn () => $wrongEnum->assertCanTransitionTo(CoverageOtherTransitionStatus::Done))
        ->toThrow(IllegalStatusTransition::class);

    $missingMethod = new CoverageNoTransitionModel;
    $missingMethod->setAttribute('status', CoverageNoTransitionStatus::Draft);

    expect(fn () => $missingMethod->assertCanTransitionTo(CoverageNoTransitionStatus::Draft))
        ->toThrow(LogicException::class);

    expect(fn () => $wrongEnum->assertCanTransitionTo(CoverageTransitionStatus::Draft))
        ->toThrow(IllegalStatusTransition::class);

    expect(fn () => $wrongEnum->assertCanTransitionTo(CoverageTransitionStatus::Done))
        ->not->toThrow(Throwable::class);
});
