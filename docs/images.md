# Editing images

Images can be cropped, rotated and given a focal point right in the media library – no image program needed. Open **Media**, choose an image, and use the tools above it.

Everybody who may change an image can edit it: editors and administrators all images, authors the ones they uploaded.

## Crop

1. Choose **Crop** (it is selected when the page opens).
2. Drag across the image to mark the part you want to keep. Drag inside the selection to move it, drag a corner to change its size.
3. Under **Shape** you can fix the proportions: free, like the image, square, 4 : 3, 16 : 9 or 3 : 4.
4. Choose **Crop image**.

With the keyboard: move to the image with Tab, then use the arrow keys to move the selection and Shift + arrow keys to change its size.

## Rotate

**Rotate left** and **Rotate right** turn the image by a quarter.

## Focal point

Layouts often show an image in another shape than it has: the wide picture on top of a blog card, the tiles of a gallery, the preview when a link is shared. Something has to be cut off – by default, equally on both sides.

Choose **Focal point** and click the most important spot of the image, for example a face. That spot now stays in view wherever the image is cut. The three small previews show the effect at once; the point is saved with every click.

## Undo

The image as it was uploaded is kept. **Undo all changes and restore the uploaded image** below the picture brings it back, however often the image was cropped or rotated.

Pages and posts that use the image show the edited version automatically – nothing has to be inserted again.

Animated GIFs cannot be edited, because they would lose their animation.

## For developers

- Cropping and rotating happen on the server with GD (`App\Service\MediaManager::transform()`). The edited image gets a new file name, so browsers and caches never show a stale picture; the smaller versions are created again.
- The focal point is stored per image as two numbers from 0 to 1 (`Media::getFocalX()`, `getFocalY()`).
  - In templates, add `{{ media_focus(image) }}` inside an `<img>` tag that is cut with `object-fit: cover`. It prints `style="object-position: …"` for images whose focal point is not the middle.
  - Every image that is large enough also has a version named `social`, cut to 1200 × 630 pixels around the focal point. The layout uses it for `og:image`. `media_srcset()` leaves it out, because it is not a smaller copy of the image.
  - The read API returns the point as `focal_point`, and the website export keeps it.
- `assets/controllers/image_editor_controller.js` only collects where the person dragged and clicked, as fractions of the image. Without JavaScript, rotating and restoring still work.
