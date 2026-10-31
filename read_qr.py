import sys
import re
import cv2

# Potlačení interního OpenCV logování do stderr - jinak by se při sloučení
# stdout/stderr (viz runRecognitionScript() ve weighing_lib.php, exec "2>&1")
# mohlo dostat před náš výstup a rozbít parsování první řádky v PHP.
cv2.utils.logging.setLogLevel(cv2.utils.logging.LOG_LEVEL_SILENT)

# Rozpoznání SPZ z QR kódu nalepeného na boku vozidla (rychlejší a
# spolehlivější než OCR, viz read_spz.py). Nálepka musí obsahovat text
# přesně ve tvaru "SPZ:xxxxxxx" (např. "SPZ:1AB2345") - prefix "SPZ:"
# je kvůli ochraně proti záměně s jiným QR kódem na voze (bezpečnostní
# štítky, firemní logo apod.), který by jinak mohl být mylně přečten.

PLATE_PATTERN = re.compile(r'^SPZ:([0-9A-Z]{4,8})$')

if len(sys.argv) < 2:
    print("CHYBA: Zadej cestu k fotce.")
    sys.exit(1)

image_path = sys.argv[1]

try:
    img = cv2.imread(image_path)
    if img is None:
        print(f"CHYBA: Nelze načíst obrázek {image_path}")
        sys.exit(0)

    detector = cv2.QRCodeDetector()

    try:
        ok, decoded_info, _points, _straight = detector.detectAndDecodeMulti(img)
        payloads = list(decoded_info) if ok else []
    except AttributeError:
        # Starší OpenCV bez detectAndDecodeMulti - zkusíme aspoň jeden QR kód.
        payload, _points, _straight = detector.detectAndDecode(img)
        payloads = [payload] if payload else []

    found_plate = ""
    for payload in payloads:
        match = PLATE_PATTERN.match(payload.strip().upper())
        if match:
            found_plate = match.group(1)
            break

    if found_plate:
        print(found_plate)
    else:
        print("NENALEZENO_DLE_FILTRU")
        for payload in payloads:
            print(f"- {payload}")

except Exception as e:
    print(f"CHYBA: {e}")
