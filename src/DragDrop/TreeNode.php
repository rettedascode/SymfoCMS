<?php

namespace App\DragDrop;

/**
 * Something that lives in a tree and has a place among its siblings:
 * a page, a category, a folder. TreeMover moves such things around.
 */
interface TreeNode
{
    public function getId(): ?int;

    public function getParent(): ?self;

    public function setParent(?self $parent): static;

    public function getPosition(): int;

    public function setPosition(int $position): static;
}
