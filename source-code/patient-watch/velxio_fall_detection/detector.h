#pragma once
#include <stdint.h>
#include <math.h>
struct Detector {
  enum State { NORMAL, FREE_FALL, IMPACT, POST_IMPACT, WAITING_CAMERA } state=NORMAL;
  uint32_t entered=0, quietStart=0;
  bool quiet=false;
  void reset() { state=NORMAL; entered=0; quiet=false; }
  void go(State s,uint32_t now) { state=s; entered=now; quiet=false; }
  void update(uint32_t now,float a,float w) {
    if(!isfinite(a)||!isfinite(w)) { reset(); return; }
    switch(state) {
      case NORMAL: if(a<0.55f) go(FREE_FALL,now); break;
      case FREE_FALL:
        if(now-entered>1500) reset();
        else if(a>2.40f) go(IMPACT,now);
        break;
      case IMPACT: if(now-entered>=50) go(POST_IMPACT,now); break;
      case POST_IMPACT:
        if(now-entered>5000) { reset(); break; } // demo recovery limit; not specified by PDF
        if(w<0.70f && a>=0.75f && a<=1.25f) {
          if(!quiet) {quiet=true; quietStart=now;}
          if(now-quietStart>=1000) go(WAITING_CAMERA,now);
        } else quiet=false;
        break;
      case WAITING_CAMERA: break;
    }
  }
  const char* name() const {
    switch(state) {
      case NORMAL:return "NORMAL"; case FREE_FALL:return "FREE_FALL";
      case IMPACT:return "IMPACT"; case POST_IMPACT:return "POST_IMPACT";
      default:return "WAITING_CAMERA";
    }
  }
};
