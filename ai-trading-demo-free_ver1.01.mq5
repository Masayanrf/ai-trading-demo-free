#property copyright "Copyright 2026, Masayan."
#property version   "1.01"
#property strict
#property description "ai-trading-demo-free for MT5"

#include <Trade/Trade.mqh>

input string ProfileID          = "mt5demo";
input string AIProvider         = "1"; // 1.ChatGPT / 2.Gemini / 3.Xai
input string ChatGPTApiKey      = "";
input string GeminiApiKey       = "";
input string XaiApiKey          = "";
input string RSSFeedURL         = "https://fx.reform-network.net/feed/";
input string PromptText         = "If the RSS feed content can be read, please return 1 for a long signal.";
input string SaveSettingsURL    = "https://example.com/ai-trading-demo-free/save_settings.php";
input string GetSignalURL       = "https://example.com/ai-trading-demo-free/get_signal.php";
input string UpdateSignalURL    = "https://example.com/ai-trading-demo-free/update_signal.php";
input int    AI_TriggerMode     = 1;
input int    AI_MinuteOffset    = 0;
input int    AI_HourOffset      = 0;
input int    RequestTimeoutMs   = 15000;
input int    SyncIntervalMins   = 60;
input int    Magic              = 20260405;
input double Lots               = 0.01;
input int    Slippage           = 3;
input double StopLossPips       = 0.0;
input double TakeProfitPips     = 0.0;
input bool   OnePositionOnly    = true;

CTrade trade;
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
   if(_Digits == 3 || _Digits == 5) return _Point * 10.0;
   return _Point;
}

string SelectedApiKey(){
   string provider = AIProvider;
   StringToLower(provider);
   if(provider == "2") return GeminiApiKey;
   if(provider == "3") return XaiApiKey;
   return ChatGPTApiKey;
}

bool IsTradeTriggerTime(){
   MqlDateTime dt;
   TimeToStruct(TimeCurrent(), dt);
   if(AI_TriggerMode == 1) return true;
   if(AI_TriggerMode == 2) return (dt.min % 10) == (AI_MinuteOffset % 10);
   if(AI_TriggerMode == 3) return dt.min == AI_MinuteOffset;
   if(AI_TriggerMode == 4) return (dt.hour % 4) == (AI_HourOffset % 4) && dt.min == AI_MinuteOffset;
   if(AI_TriggerMode == 5) return dt.hour == AI_HourOffset && dt.min == AI_MinuteOffset;
   return false;
}

bool ShouldRunNow(datetime lastRun){
   if(!IsTradeTriggerTime()) return false;
   MqlDateTime nowDt, lastDt;
   TimeToStruct(TimeCurrent(), nowDt);
   TimeToStruct(lastRun, lastDt);
   if(nowDt.day != lastDt.day || nowDt.mon != lastDt.mon || nowDt.year != lastDt.year) return true;
   return !(nowDt.hour == lastDt.hour && nowDt.min == lastDt.min);
}

string ToHex2(int value){
   string hex = "0123456789ABCDEF";
   return StringSubstr(hex, value / 16, 1) + StringSubstr(hex, value % 16, 1);
}

string UrlEncode(string value){
   uchar data[];
   StringToCharArray(value, data, 0, WHOLE_ARRAY, CP_UTF8);
   string out = "";
   for(int i = 0; i < ArraySize(data); i++){
      int ch = data[i];
      if(ch == 0) break;
      if((ch >= '0' && ch <= '9') || (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') || ch == '-' || ch == '_' || ch == '.' || ch == '~'){
         out += CharToString((uchar)ch);
      }else if(ch == ' '){
         out += "%20";
      }else{
         out += "%" + ToHex2(ch);
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
   int pos = StringFind(text, target);
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
   body += "&account_number=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_LOGIN));
   body += "&account_type=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_TRADE_MODE));
   body += "&symbol=" + UrlEncode(_Symbol);
   body += "&timeframe=" + IntegerToString((int)_Period);
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
   body += "&account_number=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_LOGIN));
   body += "&account_type=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_TRADE_MODE));
   body += "&symbol=" + UrlEncode(_Symbol);
   body += "&timeframe=" + IntegerToString((int)_Period);
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
   body += "&account_number=" + IntegerToString((int)AccountInfoInteger(ACCOUNT_LOGIN));
   body += "&symbol=" + UrlEncode(_Symbol);
   body += "&timeframe=" + IntegerToString((int)_Period);
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
   g_last_signal = (int)StringToInteger(ExtractValue(response, "signal"));
   g_last_signal_id = ExtractValue(response, "signal_id");
   g_last_expire_unix = (datetime)StringToInteger(ExtractValue(response, "expire_unix"));
   return true;
}

bool HasOpenBuy(){
   for(int i = PositionsTotal() - 1; i >= 0; i--){
      ulong ticket = PositionGetTicket(i);
      if(ticket == 0) continue;
      if(!PositionSelectByTicket(ticket)) continue;
      if(PositionGetString(POSITION_SYMBOL) != _Symbol) continue;
      if((int)PositionGetInteger(POSITION_MAGIC) != Magic) continue;
      if((ENUM_POSITION_TYPE)PositionGetInteger(POSITION_TYPE) == POSITION_TYPE_BUY) return true;
   }
   return false;
}

bool OpenLongPosition(){
   if(OnePositionOnly && HasOpenBuy()) return true;
   MqlTick tick;
   if(!SymbolInfoTick(_Symbol, tick)) return false;
   double price = tick.ask;
   double sl = 0.0;
   double tp = 0.0;
   double pip = PipValue();
   if(StopLossPips > 0.0) sl = NormalizeDouble(price - StopLossPips * pip, _Digits);
   if(TakeProfitPips > 0.0) tp = NormalizeDouble(price + TakeProfitPips * pip, _Digits);
   trade.SetExpertMagicNumber((long)Magic);
   trade.SetDeviationInPoints(Slippage);
   bool ok = trade.Buy(Lots, _Symbol, price, sl, tp, "ai-trading-demo-free");
   if(!ok){
      g_last_status = "trade_error";
      g_last_message = "buy_failed";
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





