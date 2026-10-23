import cv2
import sys
import numpy as np

def simple_overlay(customer_path, garment_path, output_path):
    try:
        # ছবি লোড করা
        customer = cv2.imread(customer_path)
        garment = cv2.imread(garment_path, cv2.IMREAD_UNCHANGED)

        if customer is None or garment is None:
            return

        h, w, _ = customer.shape
        
        # ড্রেসটি কাস্টমারের চওড়ার ৮০% অনুযায়ী রিসাইজ করা
        new_w = int(w * 0.8)
        aspect_ratio = garment.shape[0] / garment.shape[1]
        new_h = int(new_w * aspect_ratio)
        garment_res = cv2.resize(garment, (new_w, new_h))

        # পজিশন: ছবির মাঝখানে এবং উপর থেকে ২০% নিচে
        x_offset = (w - new_w) // 2
        y_offset = int(h * 0.2)

        # ইমেজ ব্লেন্ডিং
        y1, y2 = max(0, y_offset), min(h, y_offset + new_h)
        x1, x2 = max(0, x_offset), min(w, x_offset + new_w)

        gy1, gy2 = 0, y2 - y1
        gx1, gx2 = 0, x2 - x1

        overlay_region = garment_res[gy1:gy2, gx1:gx2]
        alpha = overlay_region[:, :, 3] / 255.0

        for c in range(0, 3):
            customer[y1:y2, x1:x2, c] = \
                (alpha * overlay_region[:, :, c] + (1 - alpha) * customer[y1:y2, x1:x2, c])

        # সেভ করা
        cv2.imwrite(output_path, customer)
        
    except Exception as e:
        print(f"Error: {str(e)}")

if __name__ == "__main__":
    if len(sys.argv) >= 4:
        simple_overlay(sys.argv[1], sys.argv[2], sys.argv[3])