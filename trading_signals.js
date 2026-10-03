// =========================================================================
// 📈 나스닥 모의 트레이딩 - 키움 HTS 0.5% / 0.2% ZigZag & ValueWhen 완벽 동기화 모듈
// =========================================================================

function calculateValueWhenZigZagStep(candles, pct) {
    if (!candles || candles.length < 2) {
        return { linePoints: [], barValues: [] };
    }
    var thresh = pct / 100.0;
    var barValues = new Array(candles.length);
    var linePoints = [];

    var trend = 0; // 1: 상승, -1: 하락
    var extremeVal = candles[0].high;
    var extremeIdx = 0;
    var activeValue = candles[0].close;

    barValues[0] = activeValue;
    linePoints.push({ time: candles[0].time, value: activeValue });

    var pivots = []; 
    
    for (var k = 1; k < candles.length; k++) {
        var h = candles[k].high;
        var l = candles[k].low;

        if (trend === 0) {
            if (h > extremeVal) {
                extremeVal = h;
                extremeIdx = k;
            }
            if (l <= extremeVal * (1 - thresh)) {
                pivots.push({ index: extremeIdx, value: extremeVal, type: 'HIGH' });
                trend = -1;
                extremeVal = l;
                extremeIdx = k;
            } else if (l < extremeVal) {
                extremeVal = l;
                extremeIdx = k;
            }
            if (h >= extremeVal * (1 + thresh)) {
                pivots.push({ index: extremeIdx, value: extremeVal, type: 'LOW' });
                trend = 1;
                extremeVal = h;
                extremeIdx = k;
            }
        } else if (trend === 1) {
            if (h > extremeVal) {
                extremeVal = h;
                extremeIdx = k;
            } else if ((extremeVal - l) / extremeVal >= thresh) {
                pivots.push({ index: extremeIdx, value: extremeVal, type: 'HIGH' });
                trend = -1;
                extremeVal = l;
                extremeIdx = k;
            }
        } else if (trend === -1) {
            if (l < extremeVal) {
                extremeVal = l;
                extremeIdx = k;
            } else if ((h - extremeVal) / extremeVal >= thresh) {
                pivots.push({ index: extremeIdx, value: extremeVal, type: 'LOW' });
                trend = 1;
                extremeVal = h;
                extremeIdx = k;
            }
        }
    }

    var pivotPtr = 0;
    var currentVal = candles[0].close;
    if (pivots.length > 0) {
        currentVal = pivots[0].value;
    }

    for (var j = 0; j < candles.length; j++) {
        while (pivotPtr < pivots.length && pivots[pivotPtr].index <= j) {
            currentVal = pivots[pivotPtr].value;
            pivotPtr++;
        }
        barValues[j] = currentVal;
        linePoints.push({ time: candles[j].time, value: currentVal });
    }

    return { linePoints: linePoints, barValues: barValues };
}

// 🎯 모든 지표 및 신호(막대 색상) 통합 계산 함수 (선과 수식 판별 일치화)
function calculateAllSignals(candleHistory) {
    var period = 20; 
    var result = {
        upper: [], middle: [], lower: [],
        high20Arr: [], low20Arr: [],
        ma2Arr: [],
        signal1Bars: [], signal2Bars: [], signal3Bars: [],
        vw02Data: null, vw05Data: null,
        lastState1: 'NONE',
        lastState2: 'NONE',
        lastState3: 'NONE',
        lastM: 0, lastU: 0, lastL: 0,
        lastH20: 0, lastL20: 0
    };

    result.vw02Data = calculateValueWhenZigZagStep(candleHistory, 0.2);
    result.vw05Data = calculateValueWhenZigZagStep(candleHistory, 0.5);
    var vw02Vals = result.vw02Data.barValues;
    var vw05Vals = result.vw05Data.barValues;

    var prevSma = null;

    for (var i = 0; i < candleHistory.length; i++) {
        var c = candleHistory[i];
        var t = c.time;

        var startIdx = Math.max(0, i - period + 1);
        var maxH = candleHistory[startIdx].high;
        var minL = candleHistory[startIdx].low;
        for (var m = startIdx + 1; m <= i; m++) {
            if (candleHistory[m].high > maxH) maxH = candleHistory[m].high;
            if (candleHistory[m].low < minL) minL = candleHistory[m].low;
        }
        result.lastH20 = maxH;
        result.lastL20 = minL;
        result.high20Arr.push({ time: t, value: maxH });
        result.low20Arr.push({ time: t, value: minL });

        // 신호 1: 0.2% 지그재그 노란선 기준 비교
        var bar1Color = '#ffffff';
        var curState1 = 'NONE';
        var v02 = vw02Vals[i];

        if (typeof v02 === 'number') {
            if (c.close > v02) {
                bar1Color = '#ff0000'; 
                curState1 = 'BUY';
            } else if (c.close < v02) {
                bar1Color = '#0000ff'; 
                curState1 = 'SELL';
            }
        }
        result.lastState1 = curState1;
        result.signal1Bars.push({ time: t, value: 100, color: bar1Color });

        // 신호 2: 2MA 및 0.5% 핑크선 복합 판별
        var ma2Curr = (i >= 1) ? ((candleHistory[i].close + candleHistory[i - 1].close) / 2.0) : c.close;
        var ma2Prev = (i >= 2) ? ((candleHistory[i - 1].close + candleHistory[i - 2].close) / 2.0) : ma2Curr;
        result.ma2Arr.push({ time: t, value: ma2Curr });

        var bar2Color = '#ffffff';
        var curState2 = 'NONE';
        
        if (i >= 1) {
            var v05 = vw05Vals[i];
            if (typeof v02 === 'number' && typeof v05 === 'number') {
                if (c.close > v02 && c.close > v05 && ma2Curr > ma2Prev) {
                    bar2Color = '#ff0000';
                    curState2 = 'BUY';
                } else if (c.close < v02 && c.close < v05 && ma2Curr < ma2Prev) {
                    bar2Color = '#0000ff';
                    curState2 = 'SELL';
                }
            }
        }
        result.lastState2 = curState2;
        result.signal2Bars.push({ time: t, value: 100, color: bar2Color });

        // 신호 3: 0.5% 핑크선 기반 볼린저/이평 연동 판별
        var bar3Color = '#ffffff';
        var curState3 = 'NONE';

        if (i >= 19) {
            var aSum = 0;
            for (var ap = 0; ap < 5; ap++) {
                aSum += candleHistory[i - ap].close;
            }
            var A_val = aSum / 5.0;

            var diffs = [];
            for (var dp = Math.max(0, i - 19); dp <= i; dp++) {
                var subSum = 0;
                for (var sp = 0; sp < 5; sp++) {
                    if (dp - sp >= 0) subSum += candleHistory[dp - sp].close;
                }
                var subA = subSum / 5.0;
                diffs.push(candleHistory[dp].close - subA);
            }

            var negArr = diffs.filter(function(v) { return v < 0; });
            var avgNeg = negArr.length > 0 ? negArr.reduce(function(a, b) { return a + b; }, 0) / negArr.length : 0;
            var stdNeg = 0;
            if (negArr.length > 0) {
                var nVar = negArr.reduce(function(acc, val) { return acc + Math.pow(val - avgNeg, 2); }, 0) / negArr.length;
                stdNeg = Math.sqrt(nVar);
            }

            var posArr = diffs.filter(function(v) { return v > 0; });
            var avgPos = posArr.length > 0 ? posArr.reduce(function(a, b) { return a + b; }, 0) / posArr.length : 0;
            var stdPos = 0;
            if (posArr.length > 0) {
                var pVar = posArr.reduce(function(acc, val) { return acc + Math.pow(val - avgPos, 2); }, 0) / posArr.length;
                stdPos = Math.sqrt(pVar);
            }

            var xx_val = 0.1;
            var aa_buy = A_val + avgNeg - (xx_val * stdNeg);
            var aa_sell = A_val + avgPos + (xx_val * stdPos);

            var sumL = 0, sumH = 0;
            for (var bp = 0; bp < 5; bp++) {
                sumL += candleHistory[i - bp].low;
                sumH += candleHistory[i - bp].high;
            }
            var bb_l = sumL / 5.0;
            var bb_h = sumH / 5.0;

            var as1 = vw05Vals[i];

            if (typeof as1 === 'number') {
                if (c.close > as1 && bb_l > as1 && aa_buy > as1) {
                    bar3Color = '#ff0000';
                    curState3 = 'BUY';
                } else if (c.close < as1 && bb_h < as1 && aa_sell < as1) {
                    bar3Color = '#0000ff';
                    curState3 = 'SELL';
                }
            }
        }
        result.lastState3 = curState3;
        result.signal3Bars.push({ time: t, value: 100, color: bar3Color });

        // 볼린저밴드 기준선 계산
        if (i < period - 1) {
            var pSum = 0;
            for (var p = 0; p <= i; p++) pSum += candleHistory[p].close;
            var pSma = pSum / (i + 1);
            prevSma = pSma;
            continue;
        }

        var sum = 0;
        for (var j = 0; j < period; j++) {
            sum += candleHistory[i - j].close;
        }
        var sma = sum / period;

        var varianceSum = 0;
        for (var k = 0; k < period; k++) {
            varianceSum += Math.pow(candleHistory[i - k].close - sma, 2);
        }
        var stdDev = Math.sqrt(varianceSum / period);

        result.lastM = sma;
        result.lastU = sma + stdDev * 2;
        result.lastL = sma - stdDev * 2;

        result.middle.push({ time: t, value: result.lastM });
        result.upper.push({ time: t, value: result.lastU });
        result.lower.push({ time: t, value: result.lastL });
        prevSma = sma;
    }

    return result;
}