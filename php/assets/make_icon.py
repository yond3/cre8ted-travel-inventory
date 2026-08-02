"""One-off helper: crops the full Cre8ted Travel logo (icon + wordmark) down
to just the globe/plane/C mark, padded onto a square white canvas, for use
as the small sidebar icon (the full wordmark is unreadable at 44px)."""
from PIL import Image

img = Image.open("logo.png").convert("RGBA")
w, h = img.size  # 1024 x 1024

# Icon mark sits in roughly the top 68% of the square image; crop a bit of
# margin around it and pad back out to a square canvas.
left, top, right, bottom = int(w * 0.14), int(h * 0.03), int(w * 0.86), int(h * 0.685)
cropped = img.crop((left, top, right, bottom))

side = max(cropped.width, cropped.height) + 40  # a little breathing room
canvas = Image.new("RGBA", (side, side), (255, 255, 255, 255))
offset = ((side - cropped.width) // 2, (side - cropped.height) // 2)
canvas.paste(cropped, offset, cropped)
canvas.save("logo-icon.png")
print(f"Saved logo-icon.png ({side}x{side}), cropped from box {(left, top, right, bottom)}")
