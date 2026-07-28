from __future__ import annotations

import subprocess
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont


ROOT = Path(__file__).resolve().parent
SCREENSHOTS = ROOT / "screenshots"
FRAMES = ROOT / "frames"
SEGMENTS = ROOT / "segments"
OUTPUT = ROOT / "ierp-owner-dashboard-scenarios.mp4"

WIDTH = 1920
HEIGHT = 1080
FPS = 24

BACKGROUND = "#07090d"
PANEL = "#11151d"
PANEL_BORDER = "#282e39"
TEXT = "#f8fafc"
MUTED = "#aab1bd"
ACCENT = "#f59e0b"
ACCENT_DARK = "#4a3105"

FONT_REGULAR = Path(r"C:\Windows\Fonts\segoeui.ttf")
FONT_SEMIBOLD = Path(r"C:\Windows\Fonts\seguisb.ttf")
FONT_BOLD = Path(r"C:\Windows\Fonts\segoeuib.ttf")


@dataclass(frozen=True)
class Scene:
    number: str
    title: str
    description: str
    images: tuple[str, ...]
    labels: tuple[str, ...]
    page: str
    duration: float = 8.0
    crop_y: tuple[float, ...] = (0.0,)
    eyebrow: str = "OWNER WORKFLOW"


SCENES = (
    Scene(
        number="00",
        title="IERP Owner Dashboard",
        description=(
            "A silent walkthrough of the dashboard and the operational scenarios "
            "an owner can use to monitor inventory and guide daily decisions."
        ),
        images=("01-dashboard.png",),
        labels=("Live owner dashboard",),
        page="Dashboard control center",
        duration=7.0,
        eyebrow="LIVE ADMIN WALKTHROUGH",
    ),
    Scene(
        number="01",
        title="Start-of-day operational check",
        description=(
            "Begin on the Dashboard. Review draft adjustments and transfers, "
            "low-stock products, warehouse value, and recent movement activity "
            "before the team starts."
        ),
        images=("01-dashboard.png",),
        labels=("Drafts, low stock, value, and movements",),
        page="Dashboard",
        duration=8.0,
    ),
    Scene(
        number="02",
        title="Prevent stock-outs",
        description=(
            "Open Stock Levels or use the Dashboard low-stock panel. Products at "
            "or below their reorder level need purchasing or a transfer from "
            "another warehouse."
        ),
        images=("02-stock-levels.png",),
        labels=("Current availability by warehouse",),
        page="Inventory → Stock Levels",
        duration=8.0,
    ),
    Scene(
        number="03",
        title="Monitor stock value by warehouse",
        description=(
            "Compare the financial value held in each warehouse. Large "
            "differences help guide purchasing, replenishment, and distribution "
            "decisions."
        ),
        images=("01-dashboard.png",),
        labels=("Warehouse value comparison",),
        page="Dashboard → Stock value by warehouse",
        duration=8.0,
        crop_y=(0.18,),
    ),
    Scene(
        number="04",
        title="Receive incoming inventory",
        description=(
            "Use Receipts to record supplier deliveries. Capture quantities, "
            "lots, serialized devices, and the destination storage location "
            "before completing the receipt."
        ),
        images=("04-receipts.png",),
        labels=("Incoming supplier receipts",),
        page="Inventory → Operations → Receipts",
        duration=8.0,
    ),
    Scene(
        number="05",
        title="Move stock between warehouses",
        description=(
            "Create an internal transfer, dispatch it from the source warehouse, "
            "and confirm receipt at the destination so both warehouse balances "
            "stay accurate."
        ),
        images=("05-internal-transfers.png",),
        labels=("Transfer lifecycle and status",),
        page="Inventory → Operations → Internal Transfers",
        duration=8.0,
    ),
    Scene(
        number="06",
        title="Correct inventory discrepancies",
        description=(
            "Create an adjustment after a physical count, damage discovery, or "
            "data-entry correction. Keep the reason and quantity change clear "
            "for later audit."
        ),
        images=("06-adjustments.png",),
        labels=("Draft and completed adjustments",),
        page="Inventory → Operations → Adjustments",
        duration=8.0,
    ),
    Scene(
        number="07",
        title="Handle damaged or scrap stock",
        description=(
            "Use Scraps to remove unusable, damaged, or disposed products from "
            "available stock. This prevents the team from promising inventory "
            "that cannot be sold or used."
        ),
        images=("07-scraps.png",),
        labels=("Damaged and disposed inventory",),
        page="Inventory → Operations → Scraps",
        duration=8.0,
    ),
    Scene(
        number="08",
        title="Manage the product catalogue",
        description=(
            "Maintain products and their supporting catalogue data: variants, "
            "brands, categories, units, packages, attributes, and suppliers."
        ),
        images=("08-products.png",),
        labels=("Products and catalogue records",),
        page="Inventory → Products",
        duration=8.0,
    ),
    Scene(
        number="09",
        title="Track serialized devices and batches",
        description=(
            "Find an individual device by serial number and inspect its history, "
            "or use inventory lots to manage batch-controlled stock and expiry "
            "details."
        ),
        images=("09a-serialized-devices.png", "09b-inventory-lots.png"),
        labels=("Serialized Devices", "Inventory Lots"),
        page="Inventory → Products → Serialized Devices / Inventory Lots",
        duration=10.0,
    ),
    Scene(
        number="10",
        title="Import product data in bulk",
        description=(
            "Upload catalogue data through an import run, review the result, and "
            "investigate failed, duplicate, or incomplete rows before relying on "
            "the imported records."
        ),
        images=("10-import-runs.png",),
        labels=("Bulk import history and results",),
        page="Inventory → Configurations → Inventory Import Runs",
        duration=8.0,
    ),
    Scene(
        number="11",
        title="Audit stock movements",
        description=(
            "Use Stock Movements to verify when a product moved, which warehouse "
            "was involved, the movement type, the quantity, and the responsible "
            "user or source document."
        ),
        images=("11-stock-movements.png",),
        labels=("Chronological inventory ledger",),
        page="Inventory → Reporting → Stock Movements",
        duration=8.0,
    ),
    Scene(
        number="12",
        title="Set pricing rules",
        description=(
            "Maintain pricing tiers and related pricing controls. Customer "
            "pricing, price history, and floor-price overrides help protect "
            "margin while supporting negotiated selling prices."
        ),
        images=("12-pricing-tiers.png",),
        labels=("Pricing tiers and controls",),
        page="Pricing Tiers",
        duration=8.0,
    ),
    Scene(
        number="13",
        title="Review alerts and reports",
        description=(
            "Investigate inventory warnings, then use reports to review stock "
            "levels, movement activity, valuation, and other operational trends."
        ),
        images=("13a-inventory-alerts.png", "13b-inventory-reports.png"),
        labels=("Inventory Alerts", "Inventory Reports"),
        page="Inventory → Reporting",
        duration=10.0,
    ),
    Scene(
        number="14",
        title="Manage sales and customer activity",
        description=(
            "Customer Profiles is available. Sales steps—quotations, orders, "
            "delivery notes, invoices, and payments—are listed in navigation but "
            "are currently marked unavailable in this module."
        ),
        images=("14a-sales.png", "14b-customers.png"),
        labels=("Sales navigation status", "Customer Profiles"),
        page="Sales / CRM",
        duration=10.0,
    ),
    Scene(
        number="15",
        title="Control business administration",
        description=(
            "Use System and the other top modules for configuration and "
            "administration. Inventory Settings is available here; wider "
            "employee, support, and accounting pages depend on module "
            "availability and permissions."
        ),
        images=("15-inventory-settings.png",),
        labels=("Available system configuration",),
        page="System → Inventory Settings",
        duration=9.0,
    ),
    Scene(
        number="✓",
        title="A simple daily owner routine",
        description=(
            "Open the Dashboard → resolve low-stock and draft-document items → "
            "review unusual movements → use reports to make purchasing, pricing, "
            "and warehouse decisions."
        ),
        images=("01-dashboard.png",),
        labels=("Review → Resolve → Audit → Decide",),
        page="Daily control loop",
        duration=10.0,
        eyebrow="DAILY SUMMARY",
    ),
)


def font(path: Path, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(str(path), size)


def rounded_mask(size: tuple[int, int], radius: int) -> Image.Image:
    mask = Image.new("L", size, 0)
    draw = ImageDraw.Draw(mask)
    draw.rounded_rectangle((0, 0, size[0], size[1]), radius=radius, fill=255)
    return mask


def crop_to_ratio(image: Image.Image, ratio: float, y_fraction: float = 0.0) -> Image.Image:
    source_ratio = image.width / image.height

    if source_ratio > ratio:
        crop_width = int(image.height * ratio)
        left = (image.width - crop_width) // 2
        return image.crop((left, 0, left + crop_width, image.height))

    crop_height = min(image.height, int(image.width / ratio))
    max_top = max(0, image.height - crop_height)
    top = int(max_top * min(max(y_fraction, 0.0), 1.0))

    return image.crop((0, top, image.width, top + crop_height))


def cover(image: Image.Image, size: tuple[int, int], y_fraction: float = 0.0) -> Image.Image:
    cropped = crop_to_ratio(image, size[0] / size[1], y_fraction)
    return cropped.resize(size, Image.Resampling.LANCZOS)


def draw_wrapped(
    draw: ImageDraw.ImageDraw,
    text: str,
    xy: tuple[int, int],
    selected_font: ImageFont.FreeTypeFont,
    fill: str,
    max_width: int,
    line_spacing: int,
) -> int:
    words = text.split()
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
        line_box = draw.textbbox((xy[0], y), line, font=selected_font)
        y += line_box[3] - line_box[1] + line_spacing

    return y


def paste_browser_card(
    canvas: Image.Image,
    source_name: str,
    box: tuple[int, int, int, int],
    label: str,
    y_fraction: float,
) -> None:
    x1, y1, x2, y2 = box
    width = x2 - x1
    height = y2 - y1
    header_height = 56
    screenshot_height = height - header_height

    shadow = Image.new("RGBA", (width + 40, height + 40), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow)
    shadow_draw.rounded_rectangle((20, 20, width + 20, height + 20), radius=24, fill=(0, 0, 0, 130))
    shadow = shadow.filter(ImageFilter.GaussianBlur(16))
    canvas.alpha_composite(shadow, (x1 - 20, y1 - 10))

    card = Image.new("RGBA", (width, height), PANEL)
    card_draw = ImageDraw.Draw(card)
    card_draw.rounded_rectangle((0, 0, width - 1, height - 1), radius=22, outline=PANEL_BORDER, width=2)
    card_draw.line((0, header_height, width, header_height), fill=PANEL_BORDER, width=2)

    for index, color in enumerate(("#ef4444", "#f59e0b", "#22c55e")):
        cx = 24 + index * 24
        card_draw.ellipse((cx, 19, cx + 12, 31), fill=color)

    card_draw.text((116, 14), label, font=font(FONT_SEMIBOLD, 24), fill=MUTED)

    source = Image.open(SCREENSHOTS / source_name).convert("RGB")
    screenshot = cover(source, (width - 4, screenshot_height - 4), y_fraction)
    screenshot_rgba = screenshot.convert("RGBA")
    screenshot_mask = rounded_mask((width - 4, screenshot_height - 4), 18)
    card.paste(screenshot_rgba, (2, header_height + 2), screenshot_mask)
    canvas.alpha_composite(card, (x1, y1))


def render_scene(scene: Scene, index: int) -> Path:
    canvas = Image.new("RGBA", (WIDTH, HEIGHT), BACKGROUND)
    draw = ImageDraw.Draw(canvas)

    draw.rectangle((0, 0, 16, HEIGHT), fill=ACCENT)
    draw.text((70, 54), "IERP OWNER WALKTHROUGH", font=font(FONT_SEMIBOLD, 24), fill=ACCENT)
    draw.text((1724, 54), f"{index + 1:02d}/{len(SCENES):02d}", font=font(FONT_SEMIBOLD, 22), fill=MUTED)

    number_box = (70, 130, 174, 234)
    draw.rounded_rectangle(number_box, radius=24, fill=ACCENT_DARK, outline=ACCENT, width=2)
    number_font = font(FONT_BOLD, 46 if len(scene.number) <= 2 else 40)
    number_bounds = draw.textbbox((0, 0), scene.number, font=number_font)
    number_x = number_box[0] + (number_box[2] - number_box[0] - (number_bounds[2] - number_bounds[0])) // 2
    number_y = number_box[1] + (number_box[3] - number_box[1] - (number_bounds[3] - number_bounds[1])) // 2 - 4
    draw.text((number_x, number_y), scene.number, font=number_font, fill=ACCENT)

    draw.text((70, 264), scene.eyebrow, font=font(FONT_SEMIBOLD, 21), fill=MUTED)
    title_bottom = draw_wrapped(
        draw,
        scene.title,
        (70, 304),
        font(FONT_BOLD, 54),
        TEXT,
        520,
        8,
    )

    draw.line((70, title_bottom + 20, 530, title_bottom + 20), fill=PANEL_BORDER, width=2)

    description_bottom = draw_wrapped(
        draw,
        scene.description,
        (70, title_bottom + 56),
        font(FONT_REGULAR, 30),
        MUTED,
        520,
        13,
    )

    page_y = min(930, max(description_bottom + 50, 830))
    draw.text((70, page_y), "LIVE PAGE", font=font(FONT_SEMIBOLD, 19), fill=ACCENT)
    draw_wrapped(draw, scene.page, (70, page_y + 34), font(FONT_SEMIBOLD, 25), TEXT, 520, 5)

    if len(scene.images) == 1:
        paste_browser_card(
            canvas,
            scene.images[0],
            (650, 128, 1848, 974),
            scene.labels[0],
            scene.crop_y[0],
        )
    else:
        first_crop = scene.crop_y[0] if len(scene.crop_y) > 0 else 0.0
        second_crop = scene.crop_y[1] if len(scene.crop_y) > 1 else 0.0
        paste_browser_card(
            canvas,
            scene.images[0],
            (650, 128, 1848, 535),
            scene.labels[0],
            first_crop,
        )
        paste_browser_card(
            canvas,
            scene.images[1],
            (650, 567, 1848, 974),
            scene.labels[1],
            second_crop,
        )

    progress_width = 1198
    progress_x = 650
    progress_y = 1014
    draw.rounded_rectangle(
        (progress_x, progress_y, progress_x + progress_width, progress_y + 8),
        radius=4,
        fill=PANEL_BORDER,
    )
    completed = int(progress_width * (index + 1) / len(SCENES))
    draw.rounded_rectangle(
        (progress_x, progress_y, progress_x + completed, progress_y + 8),
        radius=4,
        fill=ACCENT,
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
        frames = int(scene.duration * FPS)
        fade_out_start = max(0.0, scene.duration - 0.45)
        video_filter = (
            f"zoompan=z='min(zoom+0.00018,1.018)':"
            f"x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':"
            f"d={frames}:s={WIDTH}x{HEIGHT}:fps={FPS},"
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
    concat_lines = [f"file '{path.as_posix()}'" for path in segment_paths]
    concat_path.write_text("\n".join(concat_lines) + "\n", encoding="utf-8")

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
