import os
from PIL import Image

def convert_jpg_to_webp(directory):
    """
    Recursively converts all JPG files in the given directory to WEBP format,
    maintaining the original aspect ratio and quality.
    """
    for root, _, files in os.walk(directory):
        for file in files:
            if file.lower().endswith(".jpg") or file.lower().endswith(".jpeg"):
                jpg_path = os.path.join(root, file)
                webp_path = os.path.splitext(jpg_path)[0] + ".webp"
                
                try:
                    with Image.open(jpg_path) as img:
                        img.save(webp_path, "WEBP", quality=85)
                    print(f"Converted: {jpg_path} -> {webp_path}")
                except Exception as e:
                    print(f"Error converting {jpg_path}: {e}")

if __name__ == "__main__":
    directory = input("Enter the directory path: ").strip()
    if os.path.isdir(directory):
        convert_jpg_to_webp(directory)
        print("Conversion completed.")
    else:
        print("Invalid directory path.")
