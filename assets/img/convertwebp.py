import os
from PIL import Image

def convert_jpg_to_webp(directory, target_width):
    """
    Recursively converts all JPG files in the given directory to WEBP format,
    resizing them to the specified width while maintaining aspect ratio and quality.
    """
    for root, _, files in os.walk(directory):
        for file in files:
            if file.lower().endswith(".jpg") or file.lower().endswith(".jpeg"):
                jpg_path = os.path.join(root, file)
                base_name = os.path.splitext(jpg_path)[0]
                webp_path = f"{base_name}.webp"

                try:
                    with Image.open(jpg_path) as img:
                        # Calculate new height to maintain aspect ratio
                        width_percent = target_width / float(img.size[0])
                        target_height = int((float(img.size[1]) * float(width_percent)))
                        img = img.resize((target_width, target_height), Image.LANCZOS)

                        img.save(webp_path, "WEBP", quality=85)
                    print(f"Converted: {jpg_path} -> {webp_path}")
                except Exception as e:
                    print(f"Error converting {jpg_path}: {e}")

if __name__ == "__main__":
    directory = input("Enter the directory path: ").strip()
    width_input = input("Enter the target width (e.g., 720): ").strip()

    if not width_input.isdigit():
        print("Invalid width. Please enter a numeric value.")
    else:
        target_width = int(width_input)
        if os.path.isdir(directory):
            convert_jpg_to_webp(directory, target_width)
            print("Conversion completed.")
        else:
            print("Invalid directory path.")
