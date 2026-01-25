# Scrutiny Disk Dashboard Custom

**Scrutiny**의 데이터를 기반으로 동작하는 커스텀 디스크 대시보드입니다.  
기존 Scrutiny UI보다 더 직관적이고 시각적으로 개선된 통계를 제공하며, 사용자가 직접 UI를 커스터마이징할 수 있도록 설계되었습니다.

## 📌 버전 관리 (Versioning)
- **v2 (최신)**: 루트 디렉토리에 위치하며 가장 최신의 디자인과 상세 분석 기능을 제공합니다.
- **v1 (레거시)**: [v1](./v1) 디렉토리에 위치하며, 클래식한 인터페이스를 선호하는 사용자를 위한 이전 버전입니다.

![Dashboard Preview](assets/127.0.0.1_6091_%20(3).png)

## ✨ 주요 기능

- **듀얼 뷰 모드**: 
  - **List View**: 많은 디스크를 한눈에 파악하기 좋은 리스트형 레이아웃.
  - **Card View**: 시각적 만족도가 높은 카드형 그리드 레이아웃.
- **스마트 정렬 (Drag & Drop)**: 마우스 드래그로 디스크 순서를 자유롭게 변경하고 저장 가능.
- **다언어 지원 (KO/EN)**: 한국어와 영어를 원클릭으로 전환 (설정 자동 저장).
- **프리미엄 디자인**: 글래스모피즘(Glassmorphism), 네온 글로우 효과, 5rem 여백의 쾌적한 레이아웃.
- **상세 정보 표시**:
  - 기록량(TBW) 및 사용 연수(Years) 자동 계산.
  - 디스크 온도 및 수명 시각화 바(Bar).

## 🚀 설치 방법 (Docker / Portainer)

이 프로젝트는 Docker 컨테이너로 실행됩니다. **Portainer**를 사용하면 가장 쉽게 설치할 수 있습니다.

### 방법 1: Portainer - YAML 붙여넣기 (가장 쉬움)
이미 빌드된 이미지를 Docker Hub에서 가져와 실행하는 방식입니다.

1.  **Portainer** 접속 -> **Stacks** -> **Add stack** 클릭.
2.  **Build method**에서 **Web editor** 선택.
3.  아래 내용을 붙여넣고 **Deploy the stack** 클릭.

```yaml
version: '3'
services:
  disk-dashboard:
    image: grt47/disk_info:latest
    ports:
      - "6091:80"
    environment:
      - SCRUTINY_BASE=http://192.168.1.100:8080/api/summary
      - WEAR_INVERT_CONFIG=key:value # (옵션)
    restart: always
```

### 방법 2: Portainer - Git Repository (고급)
소스 코드를 직접 빌드하여 실행하므로, 코드를 수정하면 바로 반영할 수 있습니다.

1.  **Portainer** 접속 -> **Stacks** -> **Add stack** 클릭.
2.  **Build method**에서 **Repository** 선택.
3.  **Repository URL**에 이 GitHub 저장소 주소 입력.
    *   `https://github.com/GRT47/Disk-Info.git`
4.  **Compose path**는 `docker-compose.yml` 그대로 유지.
5.  **Environment variables** (환경 변수) 설정:
    *   `SCRUTINY_BASE`: Scrutiny 서버 주소 (예: `http://127.0.0.1:8080`)
    *   `WEAR_INVERT_CONFIG`: 수명 반전이 필요한 디스크 설정 (선택 사항)
6.  **Deploy the stack** 클릭.

### 방법 3: Docker Compose (터미널)
```bash
git clone https://github.com/GRT47/Disk-Info.git
cd Disk-Info
docker-compose up -d --build
```

## ⚙️ 설정 (docker-compose.yml)

```yaml
version: '3'
services:
  disk-dashboard:
    build: .
    ports:
      - "6091:80" # 포트 변경 가능
    environment:
      - SCRUTINY_BASE=http://YOUR_SCRUTINY_IP:8080
      - WEAR_INVERT_CONFIG=key:value,key2:value2 # (옵션)
    restart: always
```

## 🎨 커스터마이징 가이드

이 프로젝트는 누구나 쉽게 수정할 수 있도록 단 하나의 파일(`disk_info.php`)에 모든 로직이 담겨 있습니다.

### 1. 색상 및 디자인 수정
`disk_info.php` 파일을 열고 `<style>` 태그 내부를 수정하세요.
- `:root` 변수에서 전체 테마 색상(`--bg`, `--accent` 등)을 변경할 수 있습니다.

### 2. 기능 및 로직 수정
`disk_info.php` 파일의 하단 `<script>` 또는 PHP 코드를 수정하세요.
- **정렬 로직**: `<script>` 내 `Sortable` 관련 부분.
- **언어 텍스트**: `const i18n = { ... }` 객체 내의 텍스트 수정.

## 📝 라이선스
MIT License
