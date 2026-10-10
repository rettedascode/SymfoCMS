<?php

namespace App\Workflow;

use App\Entity\Content;
use App\Entity\User;
use App\Enum\ContentStatus;
use App\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Moves pages and posts between "draft", "in review" and "published".
 *
 * Every change of status goes through the "content_review" workflow
 * (config/packages/workflow.yaml), so the allowed steps are defined in one
 * place and App\Workflow\ReviewGuard decides who may take them.
 */
final class ReviewWorkflow
{
    public const REQUIRED_SETTING = 'workflow.review_required';

    public const SUBMIT = 'submit';
    public const WITHDRAW = 'withdraw';
    public const APPROVE = 'approve';
    public const REJECT = 'reject';
    public const PUBLISH = 'publish';
    public const UNPUBLISH = 'unpublish';

    /** Buttons of the editor (their "name") and the step each of them asks for. */
    private const BUTTONS = [
        'publish' => [self::PUBLISH, self::APPROVE],
        'submit_review' => [self::SUBMIT],
        'withdraw' => [self::WITHDRAW],
        'approve' => [self::APPROVE],
        'reject' => [self::REJECT],
    ];

    public function __construct(
        #[Target('content_review')]
        private readonly WorkflowInterface $workflow,
        private readonly Settings $settings,
        private readonly Security $security,
    ) {
    }

    /**
     * True when posts written by authors have to be approved by an editor before they go public.
     */
    public function isReviewRequired(): bool
    {
        return true === $this->settings->get(self::REQUIRED_SETTING);
    }

    public function requireReview(bool $required): void
    {
        $this->settings->set(self::REQUIRED_SETTING, $required ? true : null);
    }

    public function can(Content $content, string $transition): bool
    {
        return $this->workflow->can($content, $transition);
    }

    /**
     * The statuses the current user may give the content, including the one it has.
     *
     * @return list<ContentStatus>
     */
    public function reachableStatuses(Content $content): array
    {
        $statuses = [$content->getStatus()];
        foreach ($this->workflow->getEnabledTransitions($content) as $transition) {
            foreach ($transition->getTos() as $place) {
                $status = ContentStatus::from($place);
                if (!\in_array($status, $statuses, true)) {
                    $statuses[] = $status;
                }
            }
        }

        usort($statuses, static fn (ContentStatus $a, ContentStatus $b): int => array_search($a, ContentStatus::cases(), true) <=> array_search($b, ContentStatus::cases(), true));

        return $statuses;
    }

    /**
     * Applies what the editor form asked for: one of the buttons, or another status chosen in the settings.
     *
     * The form has written the chosen status into the content already. It is taken out again
     * and brought in through the workflow, so nobody can skip a step by sending a crafted form.
     *
     * @param ContentStatus $original the status before the form was submitted
     *
     * @return bool false when the step is not allowed for this user; the content then keeps its status
     */
    public function handle(Content $content, ContentStatus $original, Request $request): bool
    {
        $chosen = $content->getStatus();
        $content->setStatus($original);

        foreach (self::BUTTONS as $button => $transitions) {
            if (!$request->request->has($button)) {
                continue;
            }

            foreach ($transitions as $transition) {
                if ($this->workflow->can($content, $transition)) {
                    $this->apply($content, $transition, $request->request->getString('review_note'));

                    return true;
                }
            }

            // "Publish" on something that is public already just saves.
            return 'publish' === $button && ContentStatus::Published === $original;
        }

        if ($chosen === $original) {
            return true;
        }

        foreach ($this->workflow->getEnabledTransitions($content) as $transition) {
            if (\in_array($chosen->value, $transition->getTos(), true)) {
                $this->apply($content, $transition->getName(), '');

                return true;
            }
        }

        return false;
    }

    public function apply(Content $content, string $transition, string $note = ''): void
    {
        $this->workflow->apply($content, $transition);

        // The note of a reviewer explains why something came back. It has done its job once the content moves on.
        $user = $this->security->getUser();
        $note = trim($note);
        if (self::REJECT === $transition && '' !== $note) {
            $content->setReviewNote(mb_substr($note, 0, 2000), $user instanceof User ? $user->getName() : null);
        } else {
            $content->setReviewNote(null, null);
        }
    }
}
