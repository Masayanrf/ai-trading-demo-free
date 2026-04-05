#property copyright "Copyright 2026, Masayan."
#property version   "1.01"
#property strict
#property description "ai-trading-demo-free for MT4"

extern string ProfileID          = "mt4demo";
extern string AIProvider         = "1"; // 1.chatgpt / 2.gemini
extern string ChatGPTApiKey      = "";
extern string GeminiApiKey       = "";
extern string RSSFeedURL         = "https://fx.reform-network.net/feed/";
extern string PromptText         = "If the RSS feed content can be read, please return 1 for a long signal.";
extern string SaveSettingsURL    = "https://example.com/ai-trading-demo-free/save_settings.php";
extern string GetSignalURL       = "https://example.com/ai-trading-demo-free/get_signal.php";
extern string UpdateSignalURL    = "https://example.com/ai-trading-demo-free/update_signal.php";
extern int    AI_TriggerMode     = 1;
extern int    AI_MinuteOffset    = 0;
extern int    AI_HourOffset      = 0;
extern int    RequestTimeoutMs   = 15000;
extern int    SyncIntervalMins   = 60;
extern int    Magic              = 20260404;
extern double Lots               = 0.01;
extern int    Slippage           = 3;
extern double StopLossPips       = 0.0;
extern double TakeProfitPips     = 0.0;
extern bool   OnePositionOnly    = true;

datetime g_last_settings_sync = 0;
datetime g_last_settings_try = 0;
datetime g_last_update_try = 0;
int      g_last_signal = 0;
string   g_last_status = "idle";
string   g_last_message = "";
string   g_last_signal_id = "";
string   g_last_executed_signal_id = "";
datetime g_last_expire_unix = 0;

bool IsBacktestMode(){ return (bool)MQLInfoInteger(MQL_TESTER); }
string BuildBacktestWarningMessage(){ return "Error\nThis EA does not support backtesting.\nPHP and AI access are disabled in tester."; }

double PipValue(){
   if(Digits == 3 || Digits == 5) return Point * 10.0;
   return Point;
}

string SelectedApiKey(){
   string provider = AIProvider;
   StringToLower(provider);
   if(provider == "2") return GeminiApiKey;
   return ChatGPTApiKey;
}

bool IsTradeTriggerTime(){
   datetime now = TimeCurrent();
   int hour = TimeHour(now);
   int minute = TimeMinute(now);
   if(AI_TriggerMode == 1) return true;
   if(AI_TriggerMode == 2) return (minute % 10) == (AI_MinuteOffset % 10);
   if(AI_TriggerMode == 3) return minute == AI_MinuteOffset;
   if(AI_TriggerMode == 4) return (hour % 4) == (AI_HourOffset % 4) && minute == AI_MinuteOffset;
   if(AI_TriggerMode == 5) return hour == AI_HourOffset && minute == AI_MinuteOffset;
   return false;
}

bool ShouldRunNow(datetime lastRun){
   if(!IsTradeTriggerTime()) return false;
   int nowKey = TimeHour(TimeCurrent()) * 100 + TimeMinute(TimeCurrent());
   int lastKey = TimeHour(lastRun) * 100 + TimeMinute(lastRun);
   if(TimeDay(TimeCurrent()) != TimeDay(lastRun) || TimeMonth(TimeCurrent()) != TimeMonth(lastRun) || TimeYear(TimeCurrent()) != TimeYear(lastRun)) return true;
   return nowKey != lastKey;
}

string UrlEncode(string value){
   uchar data[];
   StringToCharArray(value, data, 0, WHOLE_ARRAY, CP_UTF8);
   string out = "";
   string hex = "0123456789ABCDEF";
   for(int i = 0; i < ArraySize(data); i++){
      int ch = data[i];
      if(ch == 0) break;
      if((ch >= '0' && ch <= '9') || (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') || ch == '-' || ch == '_' || ch == '.' || ch == '~'){
         out += CharToString((uchar)ch);
      }else if(ch == ' '){
         out += "%20";
      }else{
         out += "%" + StringSubstr(hex, ch / 16, 1) + StringSubstr(hex, ch % 16, 1);
      }
   }
   return out;
}

bool HttpPost(string url, string body, string &responseText, int &httpCode){
   responseText = "";
   httpCode = -1;
   char post[];
   StringToCharArray(body, post, 0, WHOLE_ARRAY, CP_UTF8);
   char result[];
   string headers = "Content-Type: application/x-www-form-urlencoded\r\n";
   string resultHeaders = "";
   ResetLastError();
   int res = WebRequest("POST", url, headers, RequestTimeoutMs, post, result, resultHeaders);
   if(res == -1){
      responseText = "webrequest_error=" + IntegerToString(GetLastError());
      return false;
   }
   httpCode = res;
   responseText = CharArrayToString(result, 0, -1, CP_UTF8);
   return true;
}

string ExtractValue(string text, string key){
   string target = key + "=";
   int pos = StringFind(text, target, 0);
   if(pos < 0) return "";
   int start = pos + StringLen(target);
   int end = StringFind(text, "\n", start);
   if(end < 0) end = StringLen(text);
   return StringSubstr(text, start, end - start);
}

bool SyncSettings(){
   g_last_settings_try = TimeCurrent();
   string body = "";
   body += "profile_id=" + UrlEncode(ProfileID);
   body += "&account_number=" + IntegerToString(AccountNumber());
   body += "&account_type=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_TRADE_MODE));
   body += "&symbol=" + UrlEncode(Symbol());
   body += "&timeframe=" + IntegerToString(Period());
   body += "&provider=" + UrlEncode(AIProvider);
   body += "&api_key=" + UrlEncode(SelectedApiKey());
   body += "&rss_feed_url=" + UrlEncode(RSSFeedURL);
   body += "&prompt_text=" + UrlEncode(PromptText);
   body += "&trigger_mode=" + IntegerToString(AI_TriggerMode);
   body += "&minute_offset=" + IntegerToString(AI_MinuteOffset);
   body += "&hour_offset=" + IntegerToString(AI_HourOffset);
   body += "&magic=" + IntegerToString(Magic);
   string response = "";
   int httpCode = -1;
   bool ok = HttpPost(SaveSettingsURL, body, response, httpCode);
   if(ok && httpCode == 200){
      g_last_settings_sync = TimeCurrent();
      return true;
   }
   g_last_status = "sync_error";
   g_last_message = response;
   return false;
}

bool RequestSignalUpdate(){
   string body = "";
   body += "profile_id=" + UrlEncode(ProfileID);
   body += "&account_number=" + IntegerToString(AccountNumber());
   body += "&account_type=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_TRADE_MODE));
   body += "&symbol=" + UrlEncode(Symbol());
   body += "&timeframe=" + IntegerToString(Period());
   string response = "";
   int httpCode = -1;
   bool ok = HttpPost(UpdateSignalURL, body, response, httpCode);
   g_last_update_try = TimeCurrent();
   if(!ok || httpCode != 200){
      g_last_status = "update_error";
      g_last_message = response;
      return false;
   }
   return true;
}

bool FetchSignal(){
   string body = "";
   body += "profile_id=" + UrlEncode(ProfileID);
   body += "&account_number=" + IntegerToString(AccountNumber());
   body += "&symbol=" + UrlEncode(Symbol());
   body += "&timeframe=" + IntegerToString(Period());
   body += "&provider=" + UrlEncode(AIProvider);
   string response = "";
   int httpCode = -1;
   bool ok = HttpPost(GetSignalURL, body, response, httpCode);
   if(!ok || httpCode != 200){
      g_last_status = "get_error";
      g_last_message = response;
      return false;
   }
   g_last_status = ExtractValue(response, "status");
   g_last_message = ExtractValue(response, "message");
   g_last_signal = StrToInteger(ExtractValue(response, "signal"));
   g_last_signal_id = ExtractValue(response, "signal_id");
   g_last_expire_unix = (datetime)StrToInteger(ExtractValue(response, "expire_unix"));
   return true;
}

bool HasOpenBuy(){
   for(int i = OrdersTotal() - 1; i >= 0; i--){
      if(!OrderSelect(i, SELECT_BY_POS, MODE_TRADES)) continue;
      if(OrderSymbol() != Symbol()) continue;
      if(OrderMagicNumber() != Magic) continue;
      if(OrderType() == OP_BUY) return true;
   }
   return false;
}

bool OpenLongPosition(){
   if(OnePositionOnly && HasOpenBuy()) return true;
   double ask = NormalizeDouble(Ask, Digits);
   double sl = 0.0;
   double tp = 0.0;
   double pip = PipValue();
   if(StopLossPips > 0.0) sl = NormalizeDouble(ask - StopLossPips * pip, Digits);
   if(TakeProfitPips > 0.0) tp = NormalizeDouble(ask + TakeProfitPips * pip, Digits);
   int ticket = OrderSend(Symbol(), OP_BUY, Lots, ask, Slippage, sl, tp, "ai-trading-demo-free", Magic, 0, clrBlue);
   if(ticket < 0){
      g_last_status = "trade_error";
      g_last_message = "order_send_failed=" + IntegerToString(GetLastError());
      return false;
   }
   g_last_executed_signal_id = g_last_signal_id;
   g_last_status = "long_opened";
   g_last_message = "demo_force_long";
   Alert("Long entry executed successfully. Operation check OK.");
   return true;
}

void RefreshComment(){
   string s = "ai-trading-demo-free\n";
   s += "Provider=" + AIProvider + "\n";
   s += "Status=" + g_last_status + "\n";
   s += "Signal=" + IntegerToString(g_last_signal) + "\n";
   s += "SignalID=" + g_last_signal_id + "\n";
   s += "ExecutedSignalID=" + g_last_executed_signal_id + "\n";
   s += "Message=" + g_last_message + "\n";
   Comment(s);
}

int OnInit(){
   if(IsBacktestMode()){
      Comment(BuildBacktestWarningMessage());
      return(INIT_SUCCEEDED);
   }
   SyncSettings();
   RefreshComment();
   return(INIT_SUCCEEDED);
}

void OnTick(){
   if(IsBacktestMode()){
      Comment(BuildBacktestWarningMessage());
      return;
   }

   if((TimeCurrent() - g_last_settings_try) >= SyncIntervalMins * 60){
      SyncSettings();
   }

   if(ShouldRunNow(g_last_update_try)){
      g_last_update_try = TimeCurrent();
      if(SyncSettings() && RequestSignalUpdate() && FetchSignal()){
         if(g_last_status == "ready" && g_last_signal == 1 && g_last_signal_id != "" && g_last_signal_id != g_last_executed_signal_id){
            OpenLongPosition();
         }
      }
   }

   RefreshComment();
}





