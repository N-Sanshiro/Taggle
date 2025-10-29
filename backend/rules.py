import re

MATERIAL_KEYWORDS = {
    "綿": "cotton",
    "コットン": "cotton",
    "ポリエステル": "polyester",
    "麻": "linen",
    "ウール": "wool",
    "毛": "wool",
    "シルク": "silk",
}

WARNING_PATTERNS = [
    (r"漂白.*不可|塩素.*不可|Bleach.*(not|no)", "漂白は不可"),
    (r"タンブル.*不可|乾燥機.*不可|Do not tumble", "乾燥機は不可"),
    (r"手洗い|Hand wash", "手洗い推奨"),
    (r"ドライ.*可|Dry clean", "ドライクリーニング可"),
    (r"アイロン.*(低温|110|120|130)", "アイロンは低温"),
    (r"30.?C|30度|30℃", "30℃以下で洗濯"),
    (r"40.?C|40度|40℃", "40℃以下で洗濯"),
]

def extract_features(text: str):
    features = {"warnings": []}

    # 素材
    for jp, _ in MATERIAL_KEYWORDS.items():
        if jp in text:
            features["material"] = jp
            break

    # 温度
    m = re.search(r"(\d{2})\s*℃|(\d{2})\s*C", text)
    if m:
        temp = next(g for g in m.groups() if g)
        features["wash_temp"] = int(temp)

    # 注意文言
    for pat, label in WARNING_PATTERNS:
        if re.search(pat, text, flags=re.IGNORECASE):
            features["warnings"].append(label)

    # 名前（フォールバック）
    first = text.strip().splitlines()[0] if text.strip() else ""
    features["name"] = first[:50]
    return features

def make_recommendations(feats: dict):
    lines = []
    temp = feats.get("wash_temp")
    mat = feats.get("material", "")
    warns = feats.get("warnings", [])

    if temp:
        lines.append(f"洗濯は{temp}℃以下のコースを推奨")
    if "手洗い推奨" in warns or mat in ("ウール", "シルク"):
        lines.append("デリケート(手洗い/ドライ)コースを推奨")
    if "乾燥機は不可" in warns or mat in ("ウール", "綿"):
        lines.append("乾燥機は避け、陰干し・平干しを基本に")
    if "漂白は不可" in warns:
        lines.append("色柄物用洗剤を使用し、漂白剤は使わない")
    if not lines:
        lines.append("標準コースでOK。ネット使用・裏返し推奨")

    return {"summary": " / ".join(lines)}

def weather_based_advice(temp, humidity, precip):
    tips = []
    if temp is not None and temp >= 22 and humidity is not None and humidity <= 60 and (not precip or precip == 0):
        tips.append("天気が良く乾きやすいので厚手の衣類の洗濯日和")
    if humidity is not None and humidity >= 70:
        tips.append("部屋干しは除湿機・サーキュレーター併用推奨")
    if precip and precip > 0:
        tips.append("雨のため外干しは避ける")
    if not tips:
        tips.append("通常どおりの洗濯でOK")
    return tips
