import cv2
import sys
import os

def overlay_garment(customer_img_path, garment_img_path, output_path):
    # ছবিগুলো লোড করা
    customer = cv2.imread(customer_img_path)
    garment = cv2.imread(garment_img_path, cv2.IMREAD_UNCHANGED) # PNG হিসেবে পড়া

    # ১. এখানে আমরা কাস্টমারের ছবির সাইজ অনুযায়ী পোশাকের সাইজ রিসাইজ করব
    # (আপাতত বেসিক কম্পোজিশন, পরে আমরা AI দিয়ে কাঁধ ডিটেক্ট করতে পারব)
    garment_resized = cv2.resize(garment, (customer.shape[1], customer.shape[0]))

    # ২. আলফা চ্যানেল আলাদা করা (স্বচ্ছতা বজায় রাখার জন্য)
    alpha_garment = garment_resized[:, :, 3] / 255.0
    alpha_customer = 1.0 - alpha_garment

    # ৩. ইমেজ কম্বাইন করা
    for c in range(0, 3):
        customer[:, :, c] = (alpha_garment * garment_resized[:, :, c] +
                              alpha_customer * customer[:, :, c])

    # ৪. ফাইনাল ইমেজ সেভ করা
    cv2.imwrite(output_path, customer)

if __name__ == "__main__":
    overlay_garment(sys.argv[1], sys.argv[2], sys.argv[3])