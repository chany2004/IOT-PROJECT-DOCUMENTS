#include "velxio_document/camera/camera_safety.h"

#include <cassert>
#include <iostream>
#include <string>
#include <vector>

using CameraSafety::RequestParser;
using State = RequestParser::State;

static RequestParser parse(const std::string& bytes) {
  RequestParser parser;
  for (char byte : bytes) parser.feed(byte);
  return parser;
}

static const std::string testKey(64, 'k');

static void requestCompatibility() {
  for (const char* version : {"HTTP/1.0", "HTTP/1.1"}) {
    for (const char* endpoint : {"/capture", "/preview", "/health"}) {
      const auto parser = parse(std::string("GET ") + endpoint + " " + version +
          "\r\nHost: camera.local\r\nx-CaMeRa-KeY:\t " + testKey + " \t\r\n\r\n");
      assert(parser.state() == State::Complete);
      assert(parser.isGet());
      assert(std::string(parser.path()) == endpoint);
      assert(parser.authorized(testKey.c_str()));
    }
  }
  auto parser = parse("GET /capture?cache=123 HTTP/1.1\r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(std::string(parser.path()) == "/capture");
  assert(!parser.authorized(testKey.c_str()));

  // The firmware routes exact paths; parsing must not turn a suffix into
  // an authenticated capture endpoint.
  parser = parse("GET /capture-other HTTP/1.1\r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(std::string(parser.path()) == "/capture-other");
  parser = parse("POST /capture HTTP/1.1\r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(!parser.isGet());
}

static void authenticationBoundaries() {
  auto parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key: " + testKey + "\r\n\r\n");
  assert(parser.authorized(testKey.c_str()));
  assert(!parser.authorized(nullptr));
  assert(!parser.authorized(""));
  assert(!parser.authorized(std::string(31, 'k').c_str()));
  assert(!parser.authorized(std::string(257, 'k').c_str()));
  std::string wrong = testKey;
  wrong.front() = 'x';
  assert(!parser.authorized(wrong.c_str()));
  wrong = testKey;
  wrong.back() = 'x';
  assert(!parser.authorized(wrong.c_str()));
  assert(!parser.authorized((testKey + "k").c_str()));

  parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key:\t \r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(!parser.authorized(testKey.c_str()));
  parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key: " + testKey +
      "\r\nx-camera-key: " + testKey + "\r\n\r\n");
  assert(parser.state() == State::BadRequest);
  assert(!parser.authorized(testKey.c_str()));

  const std::string largestKey(256, 'a');
  parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key: " + largestKey + "\r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(parser.authorized(largestKey.c_str()));
  parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key: " + std::string(257, 'a') + "\r\n\r\n");
  assert(parser.state() == State::TooLarge);
  assert(!parser.authorized(testKey.c_str()));

  // A complete key in an incomplete request must never authorize capture.
  parser = parse("GET /capture HTTP/1.1\r\nX-Camera-Key: " + testKey + "\r\n");
  assert(parser.state() == State::Reading);
  assert(!parser.authorized(testKey.c_str()));
}

static void malformedAndBoundedRequests() {
  for (const std::string& request : {
      std::string("GET http://camera/capture HTTP/1.1\r\n\r\n"),
      std::string("GET /capture HTTP/2.0\r\n\r\n"),
      std::string("GET  HTTP/1.1\r\n\r\n"),
      std::string("GET /capture HTTP/1.1 extra\r\n\r\n"),
      std::string("GET /capture HTTP/1.1\n\n"),
      std::string("GET /capture HTTP/1.1\r\nNo-colon\r\n\r\n"),
      std::string("GET /capture HTTP/1.1\r\n: value\r\n\r\n"),
      std::string("GET /cap\rture HTTP/1.1\r\n\r\n")}) {
    const auto parser = parse(request);
    assert(parser.state() == State::BadRequest);
    assert(!parser.authorized(testKey.c_str()));
  }
  for (char control : {'\0', '\x01', '\x1f', '\x7f', char(0x80)}) {
    auto parser = parse("GET /capture HTTP/1.1\r\nX-Other: " + std::string(1, control));
    assert(parser.state() == State::BadRequest);
  }

  // Bounds apply before line completion, including a peer that never sends LF.
  auto parser = parse(std::string(RequestParser::MAX_LINE, 'A'));
  assert(parser.state() == State::Reading);
  assert(parser.feed('A') == State::TooLarge);
  parser = parse("GET /capture HTTP/1.1\r\nX:" +
      std::string(RequestParser::MAX_LINE - 3, 'a') + "\r\n\r\n");
  assert(parser.state() == State::Complete);
  parser = parse("GET /capture HTTP/1.1\r\nX:" +
      std::string(RequestParser::MAX_LINE - 2, 'a') + "\r\n\r\n");
  assert(parser.state() == State::TooLarge);

  const std::string largestPath = "/" + std::string(126, 'p');
  parser = parse("GET " + largestPath + " HTTP/1.1\r\n\r\n");
  assert(parser.state() == State::Complete);
  assert(std::string(parser.path()) == largestPath);
  parser = parse("GET " + largestPath + "p HTTP/1.1\r\n\r\n");
  assert(parser.state() == State::BadRequest);

  // Fill the aggregate limit using short individual headers so the total
  // limit is exercised independently from the line limit.
  std::string headers = "GET /health HTTP/1.1\r\n";
  while (headers.size() + 200 + 2 <= RequestParser::MAX_HEADERS) {
    headers += "X:" + std::string(196, 'a') + "\r\n";
  }
  const size_t remaining = RequestParser::MAX_HEADERS - headers.size() - 2;
  assert(remaining >= 4);
  headers += "X:" + std::string(remaining - 4, 'a') + "\r\n";
  assert(headers.size() + 2 == RequestParser::MAX_HEADERS);
  parser = parse(headers + "\r\n");
  assert(parser.state() == State::Complete);
  headers.insert(headers.size() - 2, "a");
  parser = parse(headers + "\r\n");
  assert(parser.state() == State::TooLarge);

  // Terminal states are absorbing; trailing bytes cannot replace the key or
  // convert an invalid request into a valid one.
  parser = parse("GET /health HTTP/1.1\r\n\r\n");
  assert(parser.feed('G') == State::Complete);
  assert(std::string(parser.path()) == "/health");
  parser = parse("BAD\r\n");
  assert(parser.feed('\n') == State::BadRequest);
}

static void jpegFramingAndBounds() {
  const uint8_t markers[] = {0xff, 0xd8, 0xff, 0xd9};
  assert(CameraSafety::validJpeg(markers, sizeof(markers)));
  assert(!CameraSafety::validJpeg(nullptr, sizeof(markers)));
  assert(!CameraSafety::validJpeg(markers, 0));
  assert(!CameraSafety::validJpeg(markers, 3));
  const uint8_t missingStart[] = {0, 0xd8, 0xff, 0xd9};
  const uint8_t missingEnd[] = {0xff, 0xd8, 0xff, 0};
  assert(!CameraSafety::validJpeg(missingStart, sizeof(missingStart)));
  assert(!CameraSafety::validJpeg(missingEnd, sizeof(missingEnd)));
  std::vector<uint8_t> largest(CameraSafety::MAX_JPEG_BYTES, 0);
  largest[0] = 0xff;
  largest[1] = 0xd8;
  largest[largest.size() - 2] = 0xff;
  largest.back() = 0xd9;
  assert(CameraSafety::validJpeg(largest.data(), largest.size()));
  // The portable check intentionally validates framing only; PHP remains
  // responsible for decoding and judging image quality.
  largest.push_back(0xd9);
  largest[largest.size() - 2] = 0xff;
  assert(!CameraSafety::validJpeg(largest.data(), largest.size()));
}

static void deadlinesAndRecovery() {
  const uint32_t start = UINT32_MAX - 1000;
  assert(!CameraSafety::elapsed(uint32_t(start + 2999), start, 3000));
  assert(CameraSafety::elapsed(uint32_t(start + 3000), start, 3000));
  assert(CameraSafety::elapsed(uint32_t(start + 3001), start, 3000));

  CameraSafety::RecoveryLimiter limiter;
  limiter.initialAttempt(start);
  assert(limiter.attempts() == 0);
  assert(!limiter.permit(uint32_t(start + 29999)));
  for (unsigned recovery = 1; recovery <= 3; ++recovery) {
    const uint32_t now = uint32_t(start + recovery * 30000);
    assert(limiter.permit(now));
    assert(limiter.attempts() == recovery);
    assert(!limiter.permit(now));
    assert(!limiter.permit(uint32_t(now + 29999)));
  }
  assert(!limiter.permit(uint32_t(start + 120000)));
  assert(!limiter.permit(uint32_t(start + 600000)));
  limiter.successfulFrame();
  assert(limiter.attempts() == 0);
  // Success resets consecutive failures, but does not erase the cooldown.
  assert(!limiter.permit(uint32_t(start + 90001)));
  assert(limiter.permit(uint32_t(start + 120000)));
  assert(limiter.attempts() == 1);

  CameraSafety::RecoveryLimiter fresh;
  assert(fresh.permit(0));
  assert(!fresh.permit(29999));
  assert(fresh.permit(30000));
}

static void freshFrameOwnershipAndBounds() {
  struct Frame { int64_t timestamp; };
  Frame old{10}, inFlight{99}, current{110}, future{9999};
  auto check = [](std::vector<Frame*> frames, Frame* expected, unsigned expectedReturned,
                  int64_t now = 120) {
    unsigned gets = 0, discarded = 99;
    std::vector<Frame*> returned;
    Frame* result = CameraSafety::freshFrame<Frame>(100,
        [&]() { return gets < frames.size() ? frames[gets++] : nullptr; },
        [&](Frame* frame) {
          for (Frame* previous : returned) assert(previous != frame); // no double return
          returned.push_back(frame);
        }, [](Frame* frame) { return frame->timestamp; },
        [&]() { return now; }, discarded);
    assert(result == expected);
    assert(returned.size() == expectedReturned && discarded == expectedReturned);
    assert(gets <= CameraSafety::MAX_FRESH_FRAME_READS);
    for (Frame* frame : returned) assert(frame != result); // accepted ownership is caller's
  };
  check({&old, &current}, &current, 1);
  check({&old, &inFlight, &current}, &current, 2);
  check({&current}, &current, 0);
  check({&old, &inFlight, &future, &current}, nullptr, 3); // bounded; no stale fallback
  check({nullptr, &current}, nullptr, 0); // driver failure: no repeated blocking gets
  check({&old, nullptr, &current}, nullptr, 1);
  check({&current}, nullptr, 0, 100 + CameraSafety::FRESH_FRAME_BUDGET_US);
  // A driver read that exceeds the budget returns its buffer, not late evidence.
  unsigned discarded = 0;
  int64_t now = 100;
  unsigned returned = 0;
  auto result = CameraSafety::freshFrame<Frame>(100,
      [&]() { now += CameraSafety::FRESH_FRAME_BUDGET_US; return &current; },
      [&](Frame*) { ++returned; }, [](Frame* frame) { return frame->timestamp; },
      [&]() { return now; }, discarded);
  assert(!result && returned == 1 && discarded == 1);
  // Uptime timestamps must remain 64-bit after the 32-bit microsecond rollover.
  Frame longUptime{int64_t(UINT32_MAX) + 110};
  now = longUptime.timestamp + 10;
  result = CameraSafety::freshFrame<Frame>(longUptime.timestamp - 10,
      [&]() { return &longUptime; }, [&](Frame*) { assert(false); },
      [](Frame* frame) { return frame->timestamp; }, [&]() { return now; }, discarded);
  assert(result == &longUptime && discarded == 0);
}

int main() {
  requestCompatibility();
  authenticationBoundaries();
  malformedAndBoundedRequests();
  jpegFramingAndBounds();
  deadlinesAndRecovery();
  freshFrameOwnershipAndBounds();
  std::cout << "PASS: camera request/auth bounds, JPEG framing, recovery limits, clock wrap, fresh-frame ownership/deadlines\n";
}
