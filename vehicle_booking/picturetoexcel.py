import pytesseract
from PIL import Image
import pandas as pd

img = Image.open("excel.png")
text = pytesseract.image_to_string(img)

# Simple CSV creation
rows = [line.split() for line in text.split("\n") if line.strip()]
df = pd.DataFrame(rows)
df.to_csv("output.csv", index=False)
