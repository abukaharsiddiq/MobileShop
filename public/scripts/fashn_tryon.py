import requests
import time
import sys

def call_fashn_api(customer_url, garment_url, api_key):
    api_url = "https://api.fashn.ai/v1/run"
    
    headers = {
        "Authorization": f"Bearer {api_key}",
        "Content-Type": "application/json"
    }
    
    data = {
        "model_image": customer_url,
        "garment_image": garment_url,
        "category": "tops", # বা "dresses" আপনার প্রোডাক্ট অনুযায়ী
        "nsfw_filter": True,
        "cover_feet": False,
        "adjust_hands": True
    }

    response = requests.post(api_url, headers=headers, json=data)
    result = response.json()
    
    if "id" in result:
        prediction_id = result["id"]
        # রেজাল্ট তৈরি হওয়া পর্যন্ত অপেক্ষা করা (Polling)
        while True:
            status_res = requests.get(f"https://api.fashn.ai/v1/status/{prediction_id}", headers=headers)
            status_data = status_res.json()
            if status_data["status"] == "completed":
                return status_data["output"][0] # আউটপুট ইমেজের লিঙ্ক
            elif status_data["status"] == "failed":
                return None
            time.sleep(2)
    return None

if __name__ == "__main__":
    # লারাভেল থেকে পাঠানো আর্গুমেন্ট
    # python fashn_tryon.py <customer_url> <garment_url> <api_key>
    output_url = call_fashn_api(sys.argv[1], sys.argv[2], sys.argv[3])
    print(output_url)