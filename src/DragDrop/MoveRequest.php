<?php

namespace App\DragDrop;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * What the drag-and-drop layer sends when something was moved on a screen
 * that saves at once: which item, under which parent, at which position.
 *
 * It only says that the request is well-formed. Whether the move is allowed
 * is decided by the controller (voters) and by TreeMover (structure).
 */
final class MoveRequest
{
    private function __construct(
        public readonly int $id,
        public readonly ?int $parent,
        public readonly int $position,
    ) {
    }

    /**
     * @throws BadRequestHttpException when the body is not {"id": 1, "parent": null|2, "position": 0}
     */
    public static function fromRequest(Request $request): self
    {
        try {
            $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('The move is not valid JSON.');
        }

        $id = \is_array($data) ? ($data['id'] ?? null) : null;
        $parent = \is_array($data) ? ($data['parent'] ?? null) : null;
        $position = \is_array($data) ? ($data['position'] ?? null) : null;

        if (!\is_int($id) || $id < 1 || !(null === $parent || (\is_int($parent) && $parent > 0)) || !\is_int($position) || $position < 0) {
            throw new BadRequestHttpException('The move needs an id, a parent or null, and a position.');
        }

        return new self($id, $parent, $position);
    }
}
