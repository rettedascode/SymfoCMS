<?php

namespace App\Security\Voter;

use App\Entity\Media;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Every backend user can browse the library and upload images (authors need
 * them for their posts). Editing and deleting is limited to editors, admins
 * and the person who uploaded the image.
 *
 * @extends Voter<string, Media|null>
 */
final class MediaVoter extends Voter
{
    public const BROWSE = 'MEDIA_BROWSE';
    public const UPLOAD = 'MEDIA_UPLOAD';
    public const EDIT = 'MEDIA_EDIT';
    public const DELETE = 'MEDIA_DELETE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::BROWSE, self::UPLOAD => true,
            self::EDIT, self::DELETE => $subject instanceof Media,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (self::BROWSE === $attribute || self::UPLOAD === $attribute) {
            return $this->decisionManager->decide($token, ['ROLE_AUTHOR']);
        }

        if ($this->decisionManager->decide($token, ['ROLE_EDITOR'])) {
            return true;
        }

        return $subject instanceof Media
            && null !== $subject->getUploadedBy()
            && $subject->getUploadedBy()->getId() === $user->getId();
    }
}
