#pragma once

#include <stddef.h>
#include <stdint.h>
#include <string.h>

// Portable policies shared by the firmware and host regression tests.
namespace CameraSafety {
constexpr size_t MAX_JPEG_BYTES = 500000; // Match the web app's default limit.
constexpr uint32_t HEADER_TIMEOUT_MS = 3000;
constexpr uint32_t SEND_TIMEOUT_MS = 15000;
constexpr uint32_t SEND_IDLE_TIMEOUT_MS = 4000;
constexpr unsigned MAX_FRESH_FRAME_READS = 3;
constexpr int64_t FRESH_FRAME_BUDGET_US = 2000000;

inline bool elapsed(uint32_t now, uint32_t start, uint32_t duration) {
  return uint32_t(now - start) >= duration;
}

// WHEN_EMPTY can retain a frame indefinitely. Return stale buffers exactly
// once to let the single-buffer driver acquire again; never send them as
// evidence. A driver get has its own timeout, so stop immediately on nullptr.
// The caller owns only the accepted frame. No allocations or reset loops.
template <typename Frame, typename Get, typename Give, typename Timestamp, typename Clock>
Frame* freshFrame(int64_t requestedUs, Get get, Give give, Timestamp timestamp,
                  Clock clock, unsigned& discarded) {
  discarded = 0;
  for (unsigned read = 0; read < MAX_FRESH_FRAME_READS; ++read) {
    if (clock() - requestedUs >= FRESH_FRAME_BUDGET_US) break;
    Frame* frame = get();
    if (!frame) return nullptr;
    const int64_t now = clock();
    const int64_t frameUs = timestamp(frame);
    if (frameUs >= requestedUs && frameUs <= now &&
        now - requestedUs < FRESH_FRAME_BUDGET_US) return frame;
    give(frame);
    ++discarded;
  }
  return nullptr;
}

// Basic framing checks, not a JPEG decoder or proof of image quality.
inline bool validJpeg(const uint8_t* bytes, size_t length) {
  return bytes && length >= 4 && length <= MAX_JPEG_BYTES &&
      bytes[0] == 0xff && bytes[1] == 0xd8 &&
      bytes[length - 2] == 0xff && bytes[length - 1] == 0xd9;
}

class RecoveryLimiter {
 public:
  static constexpr uint32_t COOLDOWN_MS = 30000;
  static constexpr unsigned MAX_FAILURE_RECOVERIES = 3;
  void initialAttempt(uint32_t now) { lastAttempt_ = now; attempted_ = true; }
  bool permit(uint32_t now) {
    if (attempts_ >= MAX_FAILURE_RECOVERIES ||
        (attempted_ && !elapsed(now, lastAttempt_, COOLDOWN_MS))) return false;
    lastAttempt_ = now;
    attempted_ = true;
    ++attempts_;
    return true;
  }
  void successfulFrame() { attempts_ = 0; }
  unsigned attempts() const { return attempts_; }
 private:
  uint32_t lastAttempt_ = 0;
  unsigned attempts_ = 0;
  bool attempted_ = false;
};

class RequestParser {
 public:
  enum class State { Reading, Complete, BadRequest, TooLarge };
  static constexpr size_t MAX_LINE = 384;
  static constexpr size_t MAX_HEADERS = 2048;
  State feed(char c) {
    if (state_ != State::Reading) return state_;
    if (++total_ > MAX_HEADERS) return state_ = State::TooLarge;
    // Only printable ASCII, CR/LF and header whitespace are accepted.
    if ((static_cast<unsigned char>(c) < 32 && c != '\r' && c != '\n' && c != '\t') ||
        static_cast<unsigned char>(c) > 126) return state_ = State::BadRequest;
    if (c != '\n') {
      if (length_ >= MAX_LINE) return state_ = State::TooLarge;
      line_[length_++] = c;
      return state_;
    }
    if (!length_ || line_[length_ - 1] != '\r') return state_ = State::BadRequest;
    --length_;
    line_[length_] = '\0';
    // Reject embedded CR rather than permitting request/header ambiguity.
    if (strchr(line_, '\r')) return state_ = State::BadRequest;
    if (!requestSeen_) {
      requestSeen_ = true;
      char* firstSpace = strchr(line_, ' ');
      char* secondSpace = firstSpace ? strchr(firstSpace + 1, ' ') : nullptr;
      if (!firstSpace || !secondSpace || firstSpace == line_ || secondSpace == firstSpace + 1 ||
          (strcmp(secondSpace + 1, "HTTP/1.1") && strcmp(secondSpace + 1, "HTTP/1.0")))
        return state_ = State::BadRequest;
      *firstSpace = '\0';
      *secondSpace = '\0';
      get_ = strcmp(line_, "GET") == 0;
      const char* target = firstSpace + 1;
      const size_t targetLength = strlen(target);
      if (target[0] != '/' || targetLength >= sizeof(path_)) return state_ = State::BadRequest;
      memcpy(path_, target, targetLength + 1);
      if (char* query = strchr(path_, '?')) *query = '\0';
    } else if (!length_) {
      state_ = State::Complete;
    } else {
      char* colon = strchr(line_, ':');
      if (!colon || colon == line_) return state_ = State::BadRequest;
      *colon = '\0';
      if (equalIgnoringCase(line_, "x-camera-key")) {
        if (keySeen_) return state_ = State::BadRequest;
        keySeen_ = true;
        char* value = colon + 1;
        while (*value == ' ' || *value == '\t') ++value;
        size_t keyLength = strlen(value);
        while (keyLength && (value[keyLength - 1] == ' ' || value[keyLength - 1] == '\t')) --keyLength;
        if (keyLength >= sizeof(key_)) return state_ = State::TooLarge;
        memcpy(key_, value, keyLength);
        key_[keyLength] = '\0';
      }
    }
    length_ = 0;
    return state_;
  }
  State state() const { return state_; }
  bool isGet() const { return get_; }
  const char* path() const { return path_; }
  bool authorized(const char* expected) const {
    if (state_ != State::Complete || !keySeen_ || !expected) return false;
    size_t length = strlen(expected);
    if (length < 32 || length >= sizeof(key_) || strlen(key_) != length) return false;
    unsigned difference = 0;
    for (size_t i = 0; i < length; ++i) difference |= unsigned(key_[i] ^ expected[i]);
    return difference == 0;
  }
 private:
  static bool equalIgnoringCase(const char* left, const char* right) {
    while (*left && *right) {
      char c = *left++;
      if (c >= 'A' && c <= 'Z') c += 'a' - 'A';
      if (c != *right++) return false;
    }
    return !*left && !*right;
  }
  char line_[MAX_LINE + 1]{};
  char path_[128]{};
  char key_[257]{}; // The web app permits camera keys up to 256 characters.
  size_t length_ = 0, total_ = 0;
  State state_ = State::Reading;
  bool requestSeen_ = false, get_ = false, keySeen_ = false;
};
} // namespace CameraSafety
