from __future__ import annotations

import subprocess
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont


ROOT = Path(__file__).resolve().parent
SCREENSHOTS = ROOT / "screenshots"
FRAMES = ROOT / "frames"
SEGMENTS = ROOT / "segments"
OUTPUT = ROOT / "ierp-real-employee-warehouse-crud.mp4"

WIDTH = 1920
HEIGHT = 1080
FPS = 24

BACKGROUND = "#07090d"
PANEL = "#11151d"
PANEL_BORDER = "#2b3340"
TEXT = "#f8fafc"
MUTED = "#abb3c0"
ACCENT = "#f59e0b"
ACCENT_DARK = "#4a3105"
CREATE = "#22c55e"
UPDATE = "#3b82f6"
DELETE = "#ef4444"
VERIFY = "#a855f7"

FONT_REGULAR = Path(r"C:\Windows\Fonts\segoeui.ttf")
FONT_SEMIBOLD = Path(r"C:\Windows\Fonts\seguisb.ttf")
FONT_BOLD = Path(r"C:\Windows\Fonts\segoeuib.ttf")


@dataclass(frozen=True)
class Scene:
    step: str
    action: str
    title: str
    description: str
    screenshot: str
    page: str
    duration: float = 7.0
    crop_y: float = 0.0


SCENES = (
    Scene(
        step="00",
        action="SCENARIO",
        title="Manage a warehouse record",
        description=(
            "A real employee workflow in IERP: inspect the current data, create a "
            "new warehouse, verify it, update it, then safely delete the unused "
            "demo record."
        ),
        screenshot="00-dashboard.png",
        page="Live admin dashboard",
        duration=7.0,
    ),
    Scene(
        step="01",
        action="INSPECT",
        title="Start from the Dashboard",
        description=(
            "The employee begins at the control center, opens Inventory, and "
            "moves to the warehouse configuration area."
        ),
        screenshot="00-dashboard.png",
        page="Dashboard → Inventory",
    ),
    Scene(
        step="02",
        action="INSPECT",
        title="Review existing warehouses",
        description=(
            "Check the current warehouse list before adding anything. This helps "
            "avoid duplicate codes and confirms the employee has permission to "
            "create a warehouse."
        ),
        screenshot="01-warehouses-before.png",
        page="Inventory → Configurations → Warehouses",
    ),
    Scene(
        step="03",
        action="CREATE",
        title="Open New warehouse",
        description=(
            "The employee starts a new record. Name and Code identify the "
            "warehouse; Address and active status support daily receiving and "
            "transfer work."
        ),
        screenshot="02-create-blank.png",
        page="Warehouses → New warehouse",
    ),
    Scene(
        step="04",
        action="CREATE",
        title="Enter the operational details",
        description=(
            "Use a unique code, a clear purpose-based name, and the physical "
            "address. The record stays active so it can be selected in future "
            "inventory operations."
        ),
        screenshot="03-create-filled.png",
        page="Create Warehouse form",
        duration=8.0,
    ),
    Scene(
        step="05",
        action="VERIFY",
        title="Create and verify the record",
        description=(
            "After saving, review the generated warehouse page. Confirm the code, "
            "name, address, active status, and that no stock or locations were "
            "attached accidentally."
        ),
        screenshot="04-created-view.png",
        page="View Warehouse",
        duration=8.0,
    ),
    Scene(
        step="06",
        action="UPDATE",
        title="Open the Edit form",
        description=(
            "When the operational purpose changes, use Edit instead of creating "
            "a duplicate warehouse. Existing identifiers remain visible for "
            "comparison."
        ),
        screenshot="05-edit-before.png",
        page="Warehouse → Edit",
    ),
    Scene(
        step="07",
        action="UPDATE",
        title="Correct the name and address",
        description=(
            "The employee changes the warehouse purpose from Receiving to "
            "Distribution and corrects the location from Gate 2 to Gate 3."
        ),
        screenshot="06-edit-filled.png",
        page="Edit Warehouse form",
        duration=8.0,
    ),
    Scene(
        step="08",
        action="VERIFY",
        title="Save the update",
        description=(
            "Save changes and wait for the confirmation notification. The new "
            "values are now used by future inventory activity."
        ),
        screenshot="07-updated-edit.png",
        page="Saved warehouse update",
    ),
    Scene(
        step="09",
        action="VERIFY",
        title="Review the persisted values",
        description=(
            "Open the View page again. Confirm the updated name and Gate 3 "
            "address before leaving the record."
        ),
        screenshot="08-updated-view.png",
        page="View updated warehouse",
    ),
    Scene(
        step="10",
        action="DELETE",
        title="Delete only when it is safe",
        description=(
            "The demo warehouse has no locations or stock rows, so deletion is "
            "safe. The employee checks the exact record and confirms the warning "
            "dialog."
        ),
        screenshot="09-delete-confirmation.png",
        page="Delete Warehouse confirmation",
        duration=9.0,
    ),
    Scene(
        step="11",
        action="VERIFY",
        title="Confirm cleanup",
        description=(
            "The active list returns to the original three warehouses. The tagged "
            "demo record is soft-deleted, so it is removed from operations while "
            "remaining recoverable if needed."
        ),
        screenshot="10-after-delete.png",
        page="Warehouses list after deletion",
        duration=8.0,
    ),
    Scene(
        step="✓",
        action="SUMMARY",
        title="The employee control pattern",
        description=(
            "Inspect existing data → Create the record → Verify saved details → "
            "Update when operations change → Verify again → Delete only an unused "
            "record → Confirm its removal from the active list."
        ),
        screenshot="01-warehouses-before.png",
        page="Safe create, update, and delete workflow",
        duration=10.0,
    ),
)


def font(path: Path, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(str(path), size)


def action_color(action: str) -> str:
    return {
        "CREATE": CREATE,
        "UPDATE": UPDATE,
        "DELETE": DELETE,
        "VERIFY": VERIFY,
    }.get(action, ACCENT)


def crop_to_ratio(image: Image.Image, ratio: float, y_fraction: float) -> Image.Image:
    source_ratio = image.width / image.height

    if source_ratio > ratio:
        crop_width = int(image.height * ratio)
        left = (image.width - crop_width) // 2
        return image.crop((left, 0, left + crop_width, image.height))

    crop_height = min(image.height, int(image.width / ratio))
    top_limit = max(0, image.height - crop_height)
    top = int(top_limit * min(max(y_fraction, 0.0), 1.0))

    return image.crop((0, top, image.width, top + crop_height))


def draw_wrapped(
    draw: ImageDraw.ImageDraw,
    value: str,
    xy: tuple[int, int],
    selected_font: ImageFont.FreeTypeFont,
    fill: str,
    max_width: int,
    line_spacing: int,
) -> int:
    words = value.split()
    lines: list[str] = []
    current = ""

    for word in words:
        candidate = f"{current} {word}".strip()
        width = draw.textbbox((0, 0), candidate, font=selected_font)[2]

        if current and width > max_width:
            lines.append(current)
            current = word
        else:
            current = candidate

    if current:
        lines.append(current)

    y = xy[1]

    for line in lines:
        draw.text((xy[0], y), line, font=selected_font, fill=fill)
        box = draw.textbbox((xy[0], y), line, font=selected_font)
        y += box[3] - box[1] + line_spacing

    return y


def rounded_mask(size: tuple[int, int], radius: int) -> Image.Image:
    mask = Image.new("L", size, 0)
    ImageDraw.Draw(mask).rounded_rectangle((0, 0, size[0], size[1]), radius=radius, fill=255)

    return mask


def paste_browser_card(canvas: Image.Image, scene: Scene) -> None:
    x1, y1, x2, y2 = (650, 130, 1848, 970)
    width = x2 - x1
    height = y2 - y1
    header_height = 58

    shadow = Image.new("RGBA", (width + 50, height + 50), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow)
    shadow_draw.rounded_rectangle((25, 25, width + 25, height + 25), radius=24, fill=(0, 0, 0, 150))
    shadow = shadow.filter(ImageFilter.GaussianBlur(18))
    canvas.alpha_composite(shadow, (x1 - 25, y1 - 15))

    card = Image.new("RGBA", (width, height), PANEL)
    card_draw = ImageDraw.Draw(card)
    card_draw.rounded_rectangle((0, 0, width - 1, height - 1), radius=22, outline=PANEL_BORDER, width=2)
    card_draw.line((0, header_height, width, header_height), fill=PANEL_BORDER, width=2)

    for index, color in enumerate(("#ef4444", "#f59e0b", "#22c55e")):
        cx = 24 + index * 24
        card_draw.ellipse((cx, 20, cx + 12, 32), fill=color)

    card_draw.text((116, 14), scene.page, font=font(FONT_SEMIBOLD, 24), fill=MUTED)

    source = Image.open(SCREENSHOTS / scene.screenshot).convert("RGB")
    screenshot_size = (width - 4, height - header_height - 4)
    cropped = crop_to_ratio(source, screenshot_size[0] / screenshot_size[1], scene.crop_y)
    screenshot = cropped.resize(screenshot_size, Image.Resampling.LANCZOS).convert("RGBA")
    card.paste(screenshot, (2, header_height + 2), rounded_mask(screenshot_size, 18))
    canvas.alpha_composite(card, (x1, y1))


def render_scene(scene: Scene, index: int) -> Path:
    canvas = Image.new("RGBA", (WIDTH, HEIGHT), BACKGROUND)
    draw = ImageDraw.Draw(canvas)
    selected_action_color = action_color(scene.action)

    draw.rectangle((0, 0, 16, HEIGHT), fill=selected_action_color)
    draw.text((70, 54), "IERP REAL EMPLOYEE SCENARIO", font=font(FONT_SEMIBOLD, 24), fill=ACCENT)
    draw.text((1720, 54), f"{index + 1:02d}/{len(SCENES):02d}", font=font(FONT_SEMIBOLD, 22), fill=MUTED)

    number_box = (70, 128, 174, 232)
    draw.rounded_rectangle(number_box, radius=24, fill=PANEL, outline=selected_action_color, width=3)
    number_font = font(FONT_BOLD, 46 if len(scene.step) <= 2 else 40)
    bounds = draw.textbbox((0, 0), scene.step, font=number_font)
    number_x = number_box[0] + (number_box[2] - number_box[0] - (bounds[2] - bounds[0])) // 2
    number_y = number_box[1] + (number_box[3] - number_box[1] - (bounds[3] - bounds[1])) // 2 - 4
    draw.text((number_x, number_y), scene.step, font=number_font, fill=selected_action_color)

    action_width = draw.textbbox((0, 0), scene.action, font=font(FONT_BOLD, 20))[2] + 34
    draw.rounded_rectangle((70, 266, 70 + action_width, 306), radius=20, fill=selected_action_color)
    draw.text((87, 272), scene.action, font=font(FONT_BOLD, 20), fill="#ffffff")

    title_bottom = draw_wrapped(
        draw,
        scene.title,
        (70, 334),
        font(FONT_BOLD, 52),
        TEXT,
        520,
        8,
    )
    draw.line((70, title_bottom + 20, 530, title_bottom + 20), fill=PANEL_BORDER, width=2)

    description_bottom = draw_wrapped(
        draw,
        scene.description,
        (70, title_bottom + 56),
        font(FONT_REGULAR, 29),
        MUTED,
        520,
        13,
    )

    context_y = min(920, max(820, description_bottom + 44))
    draw.text((70, context_y), "EMPLOYEE ACTION", font=font(FONT_SEMIBOLD, 18), fill=selected_action_color)
    draw_wrapped(draw, scene.page, (70, context_y + 34), font(FONT_SEMIBOLD, 24), TEXT, 520, 4)

    paste_browser_card(canvas, scene)

    progress_x = 650
    progress_y = 1014
    progress_width = 1198
    draw.rounded_rectangle(
        (progress_x, progress_y, progress_x + progress_width, progress_y + 8),
        radius=4,
        fill=PANEL_BORDER,
    )
    completed = int(progress_width * (index + 1) / len(SCENES))
    draw.rounded_rectangle(
        (progress_x, progress_y, progress_x + completed, progress_y + 8),
        radius=4,
        fill=selected_action_color,
    )

    frame_path = FRAMES / f"scene-{index:02d}.png"
    canvas.convert("RGB").save(frame_path, quality=95)

    return frame_path


def run(command: list[str]) -> None:
    subprocess.run(command, check=True)


def render_segments(frame_paths: list[Path]) -> list[Path]:
    segment_paths: list[Path] = []

    for index, (scene, frame_path) in enumerate(zip(SCENES, frame_paths, strict=True)):
        segment_path = SEGMENTS / f"scene-{index:02d}.mp4"
        frame_count = int(scene.duration * FPS)
        fade_out_start = max(0.0, scene.duration - 0.45)
        video_filter = (
            f"zoompan=z='min(zoom+0.0002,1.02)':"
            f"x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':"
            f"d={frame_count}:s={WIDTH}x{HEIGHT}:fps={FPS},"
            "fade=t=in:st=0:d=0.35,"
            f"fade=t=out:st={fade_out_start}:d=0.45,"
            "format=yuv420p"
        )

        run(
            [
                "ffmpeg",
                "-hide_banner",
                "-loglevel",
                "error",
                "-y",
                "-loop",
                "1",
                "-i",
                str(frame_path),
                "-t",
                str(scene.duration),
                "-vf",
                video_filter,
                "-an",
                "-c:v",
                "libx264",
                "-preset",
                "veryfast",
                "-crf",
                "20",
                "-movflags",
                "+faststart",
                str(segment_path),
            ]
        )
        segment_paths.append(segment_path)

    return segment_paths


def concatenate_segments(segment_paths: list[Path]) -> None:
    concat_path = ROOT / "segments.txt"
    concat_path.write_text(
        "\n".join(f"file '{path.as_posix()}'" for path in segment_paths) + "\n",
        encoding="utf-8",
    )
    run(
        [
            "ffmpeg",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-f",
            "concat",
            "-safe",
            "0",
            "-i",
            str(concat_path),
            "-c",
            "copy",
            "-movflags",
            "+faststart",
            str(OUTPUT),
        ]
    )


def main() -> None:
    FRAMES.mkdir(parents=True, exist_ok=True)
    SEGMENTS.mkdir(parents=True, exist_ok=True)
    frame_paths = [render_scene(scene, index) for index, scene in enumerate(SCENES)]
    segment_paths = render_segments(frame_paths)
    concatenate_segments(segment_paths)
    print(OUTPUT)


if __name__ == "__main__":
    main()
