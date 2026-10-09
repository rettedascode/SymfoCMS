<?php

namespace App\Security\Voter;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Permissions for admin screens that have no single subject.
 *
 * @extends Voter<string, null>
 */
final class AdminAreaVoter extends Voter
{
    public const MANAGE_MENUS = 'MENU_MANAGE';
    public const MANAGE_CATEGORIES = 'CATEGORY_MANAGE';
    public const MANAGE_SETTINGS = 'SETTINGS_MANAGE';

    private const REQUIRED_ROLE = [
        self::MANAGE_MENUS => 'ROLE_ADMIN',
        self::MANAGE_CATEGORIES => 'ROLE_EDITOR',
        self::MANAGE_SETTINGS => 'ROLE_ADMIN',
    ];

    public function __construct(private readonly AccessDecisionManagerInterface $decisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return isset(self::REQUIRED_ROLE[$attribute]);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        return $this->decisionManager->decide($token, [self::REQUIRED_ROLE[$attribute]]);
    }
}
