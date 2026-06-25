#!/bin/bash
# ============================================================
# WordPress Cron Runner
# Mỗi domain sẽ được gọi tuần tự: cron-send.php → wp-cron.php
# Thêm/xóa domain: chỉ cần sửa mảng DOMAINS bên dưới
#
# Cách thêm vào crontab VPS (chạy mỗi 5 phút):
#   crontab -e
#   */5 * * * * /bin/bash /path/to/run-cron.sh >> /var/log/wp-cron-runner.log 2>&1
# ============================================================

maindomain="$1"

# ----- Khai báo danh sách domain -----
if [ "$maindomain" = "norcaljump" ]; then
  DOMAINS=(
    "marketing.norcaljump.com"
    "marketing.fremontjump.com"
  )
fi

if [ "$maindomain" = "kimlashop" ]; then
  DOMAINS=(
    "marketing.kimlashop.com"
    "marketing.daoquocdai.com"
    # "marketing.dochanh.net"
    "marketing.echbay.com"
    "marketing.hoaquavienfarmstay.com"
    "marketing.kimlaco.com"
    "marketing.sanraovatnhadat.com"
    "marketing.webgiare.org"
  )
fi

# nếu không xác định được DOMAINS thì thoát với lỗi
if [ -z "${DOMAINS[*]}" ]; then
  echo "[ERROR] Tham số không hợp lệ: '$1'. Dùng: bash run-cron.sh [norcaljump|kimlashop]"
  exit 1
fi

# ----- Cấu hình -----
TIMEOUT=30          # Giây tối đa chờ mỗi request
LOG_PREFIX="[$(date '+%Y-%m-%d %H:%M:%S')]"

# ----- Hàm gọi URL và log kết quả -----
fetch_url() {
  local url="$1"
  local http_code
  local body
  
  # Ghi body ra file tạm để tránh lẫn với http_code
  local tmpfile
  tmpfile=$(mktemp)
  
  http_code=$(curl \
    --output "$tmpfile" \
    --write-out "%{http_code}" \
    --max-time "$TIMEOUT" \
    --location \
    "$url"
  )
  
  body=$(cat "$tmpfile")
  rm -f "$tmpfile"
  
  if [ "$http_code" -ge 200 ] && [ "$http_code" -lt 400 ]; then
    echo "$LOG_PREFIX [OK $http_code] $url"
  else
    echo "$LOG_PREFIX [ERR $http_code] $url"
  fi
  
  # In response body nếu có nội dung
  if [ -n "$body" ]; then
    echo "$LOG_PREFIX [RESPONSE] $body"
  fi
}

# Dùng 10# để parse số có leading zero (07, 08, 09) đúng base 10
HOUR=$((10#$(date '+%H')))
MINUTE=$((10#$(date '+%M')))

# ----- Vòng lặp qua từng domain (tuần tự) -----
for domain in "${DOMAINS[@]}"; do
  echo "$LOG_PREFIX --- $domain ---"
  
  # 1. Plugin email queue (nếu có cài)
  fetch_url "https://${domain}/wp-content/plugins/echbay-email-queue/cron-send.php?active_wp_mail=1"
  
  # 2. MMI Auto Unsubscribe — tự throttle 6h qua bảng mmi_api_log, không cần WP-Cron
  # Mỗi 3 giờ và phút thứ 0, 30 (0:00, 0:30, 3:00, 3:30, 6:00, 6:30, ...)
  if [ $((HOUR % 3)) -eq 0 ] && [ $((MINUTE % 30)) -eq 0 ]; then
    fetch_url "https://${domain}/wp-content/plugins/mail-marketing-importer/my-cron.php"
  fi
done

# ----- Các URL riêng lẻ với điều kiện thời gian (chỉ chạy khi group = kimlashop) -----
if [ "$maindomain" = "kimlashop" ]; then
  # Phút thứ 10 của mỗi 2 giờ (0:10, 2:10, 4:10, 6:10, ...)
  if [ $((HOUR % 2)) -eq 0 ] && [ $MINUTE -eq 10 ]; then
    echo "$LOG_PREFIX [SCHEDULED] echbaydotcom_marketing (minute 10 of every 2h)"
    fetch_url "https://marketing.kimlashop.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=echbaydotcom_marketing&campaign_id=1"
  fi
  
  # Phút thứ 20 của mỗi 2 giờ (0:20, 2:20, 4:20, 6:20, ...)
  if [ $((HOUR % 2)) -eq 0 ] && [ $MINUTE -eq 20 ]; then
    echo "$LOG_PREFIX [SCHEDULED] 360buy_marketing (minute 20 of every 2h)"
    fetch_url "https://marketing.kimlashop.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=360buy_marketing&campaign_id=1"
  fi
  
  # Chạy mỗi 30 phút, cả ngày (phút 0 và phút 30 của mỗi giờ)
  if [ $((MINUTE % 30)) -eq 0 ]; then
    echo "$LOG_PREFIX [SCHEDULED] mail_marketing (every 30 minutes)"
    # nạp danh sách email cần gửi đi
    fetch_url "https://marketing.kimlashop.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=mail_marketing"
  fi
fi

# ----- Các URL riêng lẻ với điều kiện thời gian (chỉ chạy khi group = norcaljump) -----
if [ "$maindomain" = "norcaljump" ]; then
  # Phút thứ 10 của mỗi 2 giờ (0:10, 2:10, 4:10, 6:10, ...)
  if [ $((HOUR % 2)) -eq 0 ] && [ $MINUTE -eq 10 ]; then
    echo "$LOG_PREFIX [SCHEDULED] norcaljump_marketing (minute 10 of every 2h)"
    fetch_url "https://marketing.norcaljump.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=norcaljump_marketing&campaign_id=7"
  fi
  
  # Chạy mỗi 30 phút, cả ngày (phút 0 và phút 30 của mỗi giờ)
  if [ $((MINUTE % 30)) -eq 0 ]; then
    echo "$LOG_PREFIX [SCHEDULED] mail_marketing (every 30 minutes)"
    # nạp danh sách email cần gửi đi
    fetch_url "https://marketing.norcaljump.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=mail_marketing"
    # nạp danh sách email cần gửi đi
    fetch_url "https://marketing.fremontjump.com/wp-content/themes/marketing/api/v1/?token=9557ff3fc1295832f54c9fe3351d977b&action=mail_marketing"
  fi
fi

echo "$LOG_PREFIX Done."
