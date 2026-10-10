<?php

namespace App\Workflow;

use App\Entity\Content;
use App\Entity\Page;
use App\Entity\Post;
use App\Entity\User;
use App\Security\Voter\PageVoter;
use App\Security\Voter\PostVoter;
use App\Service\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\TransitionBlocker;

/**
 * Decides who may take which step of the review workflow.
 *
 * - Handing in and taking back a draft: everybody who may edit the content.
 * - Approving, sending back, publishing and unpublishing: editors and
 *   administrators - and authors for their own posts, as long as the
 *   website does not require a review.
 *
 * Code that runs without a logged-in user (console commands, fixtures,
 * imports) is not restricted.
 */
final class ReviewGuard
{
    public function __construct(
        private readonly Security $security,
        private readonly Settings $settings,
    ) {
    }

    #[AsEventListener('workflow.content_review.guard')]
    public function onGuard(GuardEvent $event): void
    {
        $content = $event->getSubject();
        if (!$content instanceof Content || !$this->security->getUser() instanceof User) {
            return;
        }

        $mayEdit = $content instanceof Page
            ? $this->security->isGranted(PageVoter::MANAGE, $content)
            : $content instanceof Post && $this->security->isGranted(PostVoter::EDIT, $content);

        $allowed = match ($event->getTransition()->getName()) {
            ReviewWorkflow::SUBMIT, ReviewWorkflow::WITHDRAW => $mayEdit,
            default => $this->security->isGranted('ROLE_EDITOR') || ($mayEdit && true !== $this->settings->get(ReviewWorkflow::REQUIRED_SETTING)),
        };

        if (!$allowed) {
            $event->addTransitionBlocker(new TransitionBlocker('This step is reserved for editors.', 'review.not_allowed'));
        }
    }
}
