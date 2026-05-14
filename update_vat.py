import requests
import time
import json

# =========================================================
# НАСТРОЙКИ
# =========================================================

WEBHOOK = "https://YOUR_PORTAL.bitrix24.kz/rest/1/WEBHOOK_CODE/"
VAT_ID = 1                # ID ставки НДС
VAT_INCLUDED = "Y"        # НДС включен в цену

# =========================================================
# ФУНКЦИЯ ВЫПОЛНЕНИЯ REST
# =========================================================

def bx_method(method, data=None):

    if data is None:
        data = {}

    url = WEBHOOK + method + ".json"

    headers = {
        "Content-Type": "application/json"
    }

    try:

        response = requests.post(
            url,
            json=data,
            headers=headers,
            timeout=60
        )

        result = response.json()

        if 'error' in result and result['error'] != '':
            print(f"\nОшибка API: {result}")
            return None

        return result

    except Exception as e:

        print(f"\nОшибка запроса: {e}")

        return None


# =========================================================
# ПОЛУЧЕНИЕ ВСЕХ ТОВАРОВ
# =========================================================

def get_all_products():

    all_products = []

    start = 0

    while True:

        print(f"Получение товаров START = {start}")

        result = bx_method(
            "crm.product.list",
            {
                "order": {
                    "ID": "ASC"
                },
                "filter": {},
                "select": [
                    "ID",
                    "NAME",
                    "VAT_ID",
                    "VAT_INCLUDED"
                ],
                "start": start
            }
        )

        if not result:
            break

        products = result.get("result", [])

        if not products:
            break

        all_products.extend(products)

        print(f"Получено: {len(products)}")
        print(f"Всего: {len(all_products)}")

        if 'next' in result:
            start = result['next']
        else:
            break

        time.sleep(0.2)

    return all_products


# =========================================================
# BATCH ОБНОВЛЕНИЕ
# =========================================================

def update_products_batch(products):

    total = len(products)
    updated = 0
    errors = 0

    batch_size = 50

    for i in range(0, total, batch_size):

        chunk = products[i:i + batch_size]

        cmd = {}

        for index, product in enumerate(chunk):

            product_id = product['ID']

            cmd[f'cmd{index}'] = (
                f"crm.product.update?"
                f"id={product_id}"
                f"&fields[VAT_ID]={VAT_ID}"
                f"&fields[VAT_INCLUDED]={VAT_INCLUDED}"
            )

        print("\n================================================")
        print(f"Обработка товаров {i + 1} - {i + len(chunk)}")
        print("================================================")

        result = bx_method(
            "batch",
            {
                "halt": 0,
                "cmd": cmd
            }
        )

        if not result:
            errors += len(chunk)
            continue

        batch_result = result.get("result", {})
        batch_errors = batch_result.get("result_error", {})

        for index, product in enumerate(chunk):

            product_id = product['ID']
            product_name = product.get('NAME', '')

            if f'cmd{index}' in batch_errors:

                errors += 1

                print(
                    f"Ошибка: "
                    f"ID={product_id} | "
                    f"{product_name}"
                )

                print(batch_errors[f'cmd{index}'])

            else:

                updated += 1

                print(
                    f"OK: "
                    f"ID={product_id} | "
                    f"{product_name}"
                )

        print("\n----------------------------------------")
        print(f"Обновлено: {updated}")
        print(f"Ошибок: {errors}")
        print(f"Осталось: {total - updated - errors}")
        print("----------------------------------------")

        # Защита от лимитов Bitrix24
        time.sleep(0.5)

    print("\n========================================")
    print("ГОТОВО")
    print("========================================")
    print(f"Всего товаров: {total}")
    print(f"Успешно обновлено: {updated}")
    print(f"Ошибок: {errors}")


# =========================================================
# MAIN
# =========================================================

if __name__ == "__main__":

    print("\n========================================")
    print("МАССОВОЕ ОБНОВЛЕНИЕ НДС В BITRIX24")
    print("========================================\n")

    products = get_all_products()

    print("\n========================================")
    print(f"Всего найдено товаров: {len(products)}")
    print("========================================\n")

    update_products_batch(products)
