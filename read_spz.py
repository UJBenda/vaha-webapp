import sys
import os
import re
import logging

# Potlačení zbytečných varování v terminálu
logging.getLogger().setLevel(logging.ERROR)
import warnings
warnings.filterwarnings("ignore")

os.environ['EASYOCR_MODULE_PATH'] = '/var/www/vaha/.easyocr_models'
import easyocr

if len(sys.argv) < 2:
    print("CHYBA: Zadej cestu k fotce.")
    sys.exit(1)

image_path = sys.argv[1]
reader = easyocr.Reader(['cs', 'en'], gpu=False, verbose=False)

try:
    # paragraph=True nám zajistí, že to čte po řádcích/blocích
    results = reader.readtext(image_path, detail=0, paragraph=True)
    
    found_plate = ""
    
    for block in results:
        # Vše na velká písmena a oprava typické chyby (písmeno O na nulu)
        block = block.upper().replace('O', '0')
        
        # Hledáme vzor: 2-3 alfanumerické znaky, volitelná mezera, 4 znaky (čísla/písmena)
        match = re.search(r'([0-9A-Z]{2,3})[\s\-]*([0-9A-Z]{4})', block)
        
        if match:
            # Složíme to dohromady bez mezer
            found_plate = match.group(1) + match.group(2)
            break 

    if found_plate:
        print(found_plate)
    else:
        print("NENALEZENO_DLE_FILTRU")
        for t in results:
            print(f"- {t}")

except Exception as e:
    print(f"CHYBA: {e}")
