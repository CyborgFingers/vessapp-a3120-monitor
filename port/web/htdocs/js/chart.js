function isset(obj)
{
    return typeof(obj) != "undefined" && obj!=null;
}
var colors = {
    blue: "#0078D8",
    red: "#c7380c",
    green:"#2e9f00",
    yellow: "#8d951e",
    black: "#000000",
    white: "#ffffff",
    grey:"#4E4E4E",
    grey2:"#969696",
    purple:"#7e008c",
    lightblue:"#5298ba"
    
};
var Chart = function(obj){
    this.maxY = 40;
    this.minY = 40;
    this.maxCountY = 9;
    this.maxCountX = 21;
    this.height = 10;
    this.width = 10;
    this.margin = {l:60, t:30, b:10, r:160};
    this.labelHeight = 12;
    this.despX = "";
    this.despY = "";
    this.enableAutoScale = false;
    this.maxData = -1;
    this.maxDisplayData = -1;
    /*def is the default data*/
    this.data = {};
    this.domNode = obj;
    this.jgAxes = null;
    this.ctx = null;
    this.imgParams = {};
    this.colors = ["#444444"];
    this.fontHeight = 10;
    this.fontWidth = 10;
    
    this.lineColors = [
        colors.blue,
        colors.red,
        colors.green,
        colors.yellow
    ];
    this.constructor = function()
    {
        var divAxes = document.createElement("div");
        var divLabels = document.createElement("div");
        var canvas = this.getCanvasNode();
        dojo.place(divAxes, this.domNode, "first");
        dojo.place(divLabels, this.domNode, "first");
        this.cloneNode(divAxes, this.domNode);
        this.cloneNode(divLabels, this.domNode);
        canvas.width =this.domNode.offsetWidth;
        canvas.height = this.domNode.offsetHeight;
        this.jgAxes = new jsGraphics(divAxes);
        this.jgLabels = new jsGraphics(divLabels);
        this.divLabels = divLabels;
        this.canvas = canvas;
    };
    this.cloneNode = function(tarNode, srcNode)
    {
        tarNode.style.position = "absolute";
        tarNode.style.left = "0px";
        tarNode.style.top = "0px";
        tarNode.style.width = srcNode.offsetWidth+"px";
        tarNode.style.height = srcNode.offsetHeight+"px";
        
    };
    this.getCanvasNode = function()
    {
        for(var i=0; i<this.domNode.childNodes.length; i++)
        {
            var node = this.domNode.childNodes[i];
            if(isset(node.tagName) && node.tagName.toLowerCase() == "canvas")
            {
                return node;
            }
        }
    };
    this.setData = function(data, id, text, unit)
    {
        if(!isset(id))
            id = "def";
        if(!isset(text))
            text = id;
        this.data[id] = {value:data, text:text, unit: unit};            
    };
    this.setParams = function(params){
        for(var key in params)
        {
            if(isset(params[key]))
            {
                this[key] = params[key];
            }
        }
        this.width = this.domNode.offsetWidth;
        this.height = this.domNode.offsetHeight; 
    };
    this.drawLabel = function(index, text, color, top, value, unit)
    {
        var labelLeft = this.imgParams.right + 10;
        var lineWidth = 20;
        var left = labelLeft + lineWidth+3;
        var dispText = text;
        if(isset(value))
        {
            dispText+=" "+value+unit;
        }
        this.jgLabels.setStroke(1);
        this.jgLabels.setColor(color);
        //this.jgLabels.drawLine(labelLeft, top+this.labelHeight/2, labelLeft + lineWidth, top+this.labelHeight/2);
        
        this.jgLabels.drawStringRect(dispText, left, top, this.width - left, "left");
//        this.jgLabels.drawString(text, labelLeft + lineWidth+3, labelTop);
        this.jgLabels.paint();
    };
    this.refresh = function(){
        this.clear();
        this.initParams();
        this.refreshAxes();
        this.refreshData();
    };
    this.refreshAxes = function(){
        this.clearAxes();
        this.drawAxes();
    };
    /* get the max */
    this.getMaxValue = function(value)
    {
        var maxValue;
        if(value = 0)
            maxValue = 10;    
        
    };
    this.refreshScale = function(){
        var maxValue = -1;
        for(var id in this.data)
        {
            var data = this.data[id].value;
            for(var i=0; i<data.length; i++){
                if(data[i]>maxValue)
                    maxValue = data[i];
            }
        }

        if(maxValue!=-1 && (this.maxY < maxValue || this.maxY>maxValue*2))
        {
            var maxDispValue = this.minY;
            while(maxDispValue<maxValue){
                maxDispValue*=2;
            }
            this.maxY = maxDispValue;
            this.refreshAxes();
        }
    };
    this.getLabelTop = function(index){
        var top = 50+index*this.labelHeight;
        if(this.divLabels.childNodes.length>0)
        {
            var divTop = this.divLabels.lastChild.offsetTop + this.divLabels.lastChild.offsetHeight;
            if(divTop>top)
                top = divTop;
        }
        return top;
    }
    this.refreshData = function(){
        this.clearLines();
        this.clearLabels();
        var index = 0;
        this.refreshScale();
        for(var id in this.data)
        {
            var top  = this.getLabelTop(index);
            var value = [];
            var curValue = null;
            if(isset(this.data[id].value))
                value = this.data[id].value;
            if(isset(value.length) && value.length>0)
                curValue = value[value.length-1];
            this.drawLabel(index, this.data[id].text, this.lineColors[index], top, curValue, this.data[id].unit);
            this.drawLines(this.data[id].value, this.lineColors[index++]);
        }
    };
    this.addData = function(data, id, text){
        if(!isset(id))
            id = "def";
        if(!isset(this.data[id]))
        {
            if(!isset(text))
                text = id;
            this.data[id] = {value:[], text:text};
        }
        this.data[id].value.push(data);
        if(this.data[id].value.length >this.maxCountX)
            this.data[id].value.shift();
        this.refreshData();
    };
    this.initParams = function(){
        this.imgParams.top = this.margin.t;
        this.imgParams.left = this.margin.l;
        this.imgParams.height = this.height - this.margin.t - this.margin.b;
        this.imgParams.width = this.width - this.margin.l - this.margin.r;
        this.imgParams.right = this.imgParams.left + this.imgParams.width;
        this.imgParams.bottom = this.imgParams.top + this.imgParams.height;
        this.imgParams.unitX = this.imgParams.width/this.maxCountX;
    };
    this.clearLabels = function()
    {
        this.jgLabels.clear();
    };
    this.clearLines = function()
    {
        var ctx = this.canvas.getContext('2d');
        /*this is used to clear lines*/
        this.canvas.width = this.canvas.width;
        if(ctx._clear)
        {
            ctx._clear();
        }
        ctx.beginPath();
        ctx.closePath();
        if(ctx._clear)
        {
            ctx._clear();
        }
        ctx.stroke();
    };
    this.clearAxes = function()
    {
        this.jgAxes.clear();
    };
    this.clear = function()
    {
        this.clearAxes();
        this.clearLines();
        this.clearLabels();
    };
    this.clearData = function()
    {
        this.data = [];
    };
    this.drawAxes = function()
    {
        this.jgAxes.setStroke(1);
        this.jgAxes.setColor(colors.grey2);
        this.jgAxes.drawLine(this.imgParams.left, this.imgParams.top, this.imgParams.left, this.imgParams.bottom);
        this.jgAxes.drawLine(this.imgParams.left, this.imgParams.bottom, this.imgParams.right, this.imgParams.bottom);

        this.jgAxes.drawStringRect(this.despY, 1, 5, this.imgParams.width, "left");
//        this.jgAxes.drawStringRect(this.rotate(this.despY), 0, 30, 20, "left");
        for(var i=0; i< this.maxCountY; i++)
        {
            var y = Math.round(this.maxY/(this.maxCountY-1)*i);
            var unitY = this.imgParams.height/this.maxY;
            var posY = this.imgParams.bottom - unitY*y;
            this.jgAxes.drawString(y+"", 20, posY - this.fontHeight/2);
            if(i!=0)
                this.jgAxes.setColor(colors.grey);
            this.jgAxes.drawLine(this.imgParams.left+1, posY, this.imgParams.right, posY);
            this.jgAxes.setColor(colors.grey2);
        }
        
//        this.jgAxes.drawString(this.despX, this.imgParams.right + 10, this.imgParams.bottom+3);
//        this.jgAxes.drawStringRect(this.despX, 0, this.imgParams.width, "right");
        //this.jgAxes.drawStringRect(this.despX, this.imgParams.left, this.imgParams.bottom+20, this.imgParams.width, "center");
        for(i=0; i< this.maxCountX; i++)
        {
            var posX = this.imgParams.left + this.imgParams.unitX*i; 
    //        this.jgAxes.drawString(i+"",posX - this.fontWidth/2 , this.imgParams.bottom+3);
            this.jgAxes.drawLine(posX, this.imgParams.bottom-3, posX, this.imgParams.bottom);
        }
        this.jgAxes.paint();
    };
    this.drawLines = function(data, color){
        var ctx = this.canvas.getContext('2d');
		ctx.lineWidth = "2";
		ctx.strokeStyle = color;
        ctx.beginPath();
        for(var i=0; i<data.length; i++)
        {
            var unitY = this.imgParams.height/this.maxY;
            var posX = this.imgParams.right - this.imgParams.unitX * (data.length-i);
            var posY = this.imgParams.bottom - unitY * data[i];
            if(posX<0)
            {
                posX = 0;
            }
            if(posY<0)
            {
                posY = 0;
            }
            if(i==0)
            {
                ctx.moveTo(posX,this.imgParams.bottom);
            }
            ctx.lineTo(posX,posY);
        }
        ctx.stroke();
    };
    this.constructor();
};            


var DataLoader = function(){
    this.dataList = [];
    this.isRandomData = false;
    this.maxCount = 21;
    this.isReady = false;
    this._addData = function(group, type, id, data)
    {
        var dataList = this.dataList;
        if(!isset(dataList[group]))
            dataList[group] = [];
        if(!isset(dataList[group][type]))
            dataList[group][type] = [];
        if(!isset(dataList[group][type][id]))
            dataList[group][type][id] = [];
        if(this.isRandomData)
        {
            dataList[group][type][id].push(this._getRanData());
        }
        else
        {
            dataList[group][type][id].push(data);
        }
        if(dataList[group][type][id].length>this.maxCount)
            dataList[group][type][id].shift();
    };
    this._getRanData = function()
    {   
        return Math.round(Math.random()*22);
    };
        
    this._parsePdData = function(data)
    {
        /*average latency, max latency, min latency, bandwidth, ios per second */
        var aveLa=0;
        var maxLa=0;
        var minLa=0;
        var band=0;
        var io=0;
		/*Max write latency, max read latency, min write latency, max write latency, aver read latency, 
		  aver write latency, read reqs, write reqs, readbandwidth, wrie band width*/
		var maxwritela =0;
		var maxreadla =0 ;
		var minwritela = 0;
		var minreadla = 0;
		var averreadla = 0;
		var averwritela = 0;
		var readreqs = 0;
		var writereqs = 0;
		var readbandw = 0;
		var writebandw=0;
		
        for(var i=0; i<data.length; i++)
        {
            var pd = data[i];
            pd.sld_BandWidth = new Number(pd.sld_BandWidth);
            pd.sld_IOReq = new Number(pd.sld_IOReq);

			//for write and read of perfStatsForRW.php
		    pd.sld_WriteBandWidth = new Number(pd.sld_WriteBandWidth);
			pd.sld_ReadBandWidth = new Number(pd.sld_ReadBandWidth);

            pd.sld_ReadReq = new Number(pd.sld_ReadReq);
			pd.sld_WriteReq = new Number(pd.sld_WriteReq);

			//for the perfStats.php
            this._addData("pd", "band", i+1, pd.sld_BandWidth);
            this._addData("pd", "ios", i+1, pd.sld_IOReq);
            this._addData("pd", "avgla", i+1, pd.sld_AverLatency);
            this._addData("pd", "maxla", i+1, pd.sld_MaxLantency);
            this._addData("pd", "minla", i+1, pd.sld_MinLantency);

			//for write and read of perfStatsForRW.php
            this._addData("pd", "writeband", i+1, pd.sld_WriteBandWidth);
            this._addData("pd", "readband", i+1, pd.sld_ReadBandWidth);
			this._addData("pd", "maxwritela", i+1, pd.sld_MaxWriteLatency);
			this._addData("pd", "maxreadla", i+1, pd.sld_MaxReadLatency);
			this._addData("pd", "minwritela", i+1, pd.sld_MinWriteLatency);
			this._addData("pd", "minreadla", i+1, pd.sld_MinReadLatency);
			this._addData("pd", "averreadla", i+1, pd.sld_AverReadLatency);
			this._addData("pd", "averwritela", i+1, pd.sld_AverWriteLatency);
			this._addData("pd", "writereqs", i+1, pd.sld_WriteReq);
			this._addData("pd", "readreqs", i+1, pd.sld_ReadReq);

			//for perfStats.php
            band+=pd.sld_BandWidth;
            io+=pd.sld_IOReq;
            aveLa+=pd.sld_AverLatency;
            maxLa+=pd.sld_MaxLantency;
            minLa+=pd.sld_MinLantency;
			//for perfStatsForRW.php
			maxwritela+=pd.sld_MaxWriteLatency;
			minwritela+=pd.sld_MinWriteLatency;
			maxreadla+=pd.sld_MaxReadLatency;
			minreadla+=pd.sld_MinReadLatency;
			averwritela+=pd.sld_AverWriteLatency;
			averreadla+=pd.sld_AverReadLatency;
			writebandw+=pd.sld_WriteBandWidth;
			readbandw+=pd.sld_ReadBandWidth;
			writereqs+=pd.sld_WriteReq;
			readreqs+=pd.sld_ReadReq;
			
        }
		//for perfStats
        this._addData("pd", "band", 0, band);
        this._addData("pd", "ios", 0, io);
        this._addData("pd", "avgla", 0, aveLa);
        this._addData("pd", "maxla", 0, maxLa);
        this._addData("pd", "minla", 0, minLa);

		//for perfStatsForRW.php
		this._addData("pd", "writeband", 0, writebandw);
        this._addData("pd", "readband", 0, readbandw);
		this._addData("pd", "maxwritela", 0, maxwritela);
		this._addData("pd", "maxreadla", 0, maxreadla);
		this._addData("pd", "minwritela", 0, minwritela);
		this._addData("pd", "minreadla", 0, minreadla);
		this._addData("pd", "averreadla", 0, averreadla);
		this._addData("pd", "averwritela", 0, averwritela);
		this._addData("pd", "writereqs", 0, writereqs);
		this._addData("pd", "readreqs", 0, readreqs);  
		
    };
    this._parseLdData = function(data)
    {
        /*average latency, max latency, min latency, bandwidth, ios per second */
        var aveLa=0;
        var maxLa=0;
        var minLa=0;
        var band=0;
        var io=0;
        var cache = 0;
        var dirty = 0;

		/*Max write latency, max read latency, min write latency, max write latency, aver read latency, 
		  aver write latency, read reqs, write reqs, readbandwidth, wrie band width*/
		var maxwritela =0;
		var maxreadla =0 ;
		var minwritela = 0;
		var minreadla = 0;
		var averreadla = 0;
		var averwritela = 0;
		var readreqs = 0;
		var writereqs = 0;
		var readbandw = 0;
		var writebandw=0;
        for(var i=0; i<data.length; i++)
        {   
            var ld = data[i]; 
			//for perfStats.php
            ld.sld_BandWidth = new Number(ld.sld_BandWidth); 
            ld.sld_IOReq = new Number(ld.sld_IOReq);
 
			//for write and read of perfStatsForRW.php
		    ld.sld_WriteBandWidth = new Number(ld.sld_WriteBandWidth);
			ld.sld_ReadBandWidth = new Number(ld.sld_ReadBandWidth);

            ld.sld_ReadReqs = new Number(ld.sld_ReadReqs);
			ld.sld_WriteReqs = new Number(ld.sld_WriteReqs);

			//for perfStats.php
            this._addData("ld", "band", i+1, ld.sld_BandWidth);
            this._addData("ld", "ios", i+1, ld.sld_IOReq);
            this._addData("ld", "avgla", i+1, ld.sld_AverLatency);
            this._addData("ld", "maxla", i+1, ld.sld_MaxLantency);
            this._addData("ld", "minla", i+1, ld.sld_MinLantency);
            this._addData("ld", "cache", i+1, ld.sld_CacheUsage);
            this._addData("ld", "dirty", i+1, ld.sld_DirtyCache);
           
			//for write and read of perfStatsForRW.php
            this._addData("ld", "writeband", i+1, ld.sld_WriteBandWidth);
            this._addData("ld", "readband", i+1, ld.sld_ReadBandWidth);
			this._addData("ld", "maxwritela", i+1, ld.sld_MaxWriteLatency);
			this._addData("ld", "maxreadla", i+1, ld.sld_MaxReadLatency);
			this._addData("ld", "minwritela", i+1, ld.sld_MinWriteLatency);
			this._addData("ld", "minreadla", i+1, ld.sld_MinReadLatency);
			this._addData("ld", "averreadla", i+1, ld.sld_AverReadLatency);
			this._addData("ld", "averwritela", i+1, ld.sld_AverWriteLatency);
			this._addData("ld", "writereqs", i+1, ld.sld_WriteReqs);
			this._addData("ld", "readreqs", i+1, ld.sld_ReadReqs);

			//for perfStats
            band+=ld.sld_BandWidth;
            io+=ld.sld_IOReq;
            aveLa+=ld.sld_AverLatency;
            maxLa+=ld.sld_MaxLantency;
            minLa+=ld.sld_MinLantency;
            cache+=ld.sld_CacheUsage;
            dirty+=ld.sld_DirtyCache;

			//for perfStatsForRW.php
			maxwritela+=ld.sld_MaxWriteLatency;
			minwritela+=ld.sld_MinWriteLatency;
			maxreadla+=ld.sld_MaxReadLatency;
			minreadla+=ld.sld_MinReadLatency;
			averwritela+=ld.sld_AverWriteLatency;
			averreadla+=ld.sld_AverReadLatency;
			writebandw+=ld.sld_WriteBandWidth;
			readbandw+=ld.sld_ReadBandWidth;
			writereqs+=ld.sld_WriteReqs;
			readreqs+=ld.sld_ReadReqs;
        } 
		//for perfStats
        this._addData("ld", "band", 0, band);
        this._addData("ld", "ios", 0, io);
        this._addData("ld", "avgla", 0, aveLa);
        this._addData("ld", "maxla", 0, maxLa);
        this._addData("ld", "minla", 0, minLa);
        this._addData("ld", "cache", 0, cache);
        this._addData("ld", "dirty", 0, dirty);

		//for perfStatsForRW.php  
		this._addData("ld", "writeband", 0, writebandw);
        this._addData("ld", "readband", 0, readbandw);
		this._addData("ld", "maxwritela", 0, maxwritela);
		this._addData("ld", "maxreadla", 0, maxreadla);
		this._addData("ld", "minwritela", 0, minwritela);
		this._addData("ld", "minreadla", 0, minreadla);
		this._addData("ld", "averreadla", 0, averreadla);
		this._addData("ld", "averwritela", 0, averwritela);
		this._addData("ld", "writereqs", 0, writereqs);
		this._addData("ld", "readreqs", 0, readreqs);  
    };
    this._parsePortsData = function(data)
    {  
        /*average latency, max latency, min latency, bandwidth, ios per second */
        var aveLa=0;
        var maxLa=0;
        var minLa=0;
        var band=0;
        var io=0;

		/*Max write latency, max read latency, min write latency, max write latency, aver read latency, 
		  aver write latency, read reqs, write reqs, readbandwidth, wrie band width*/
		var maxwritela =0;
		var maxreadla =0 ;
		var minwritela = 0;
		var minreadla = 0;
		var averreadla = 0;
		var averwritela = 0;
		var readreqs = 0;
		var writereqs = 0;
		var readbandw = 0;
		var writebandw=0;  
        for(var i=0; i<data.length; i++)
        {
            var port = data[i];
            port.sld_BandWidth = new Number(port.sld_BandWidth);
            port.sld_IOReq = new Number(port.sld_IOReq);
			
			//for write and read of perfStatsForRW.php
		    port.sld_WriteBandWidth = new Number(port.sld_WriteBandWidth);
			port.sld_ReadBandWidth = new Number(port.sld_ReadBandWidth);

            port.sld_ReadReq = new Number(port.sld_ReadReq);
			port.sld_WriteReq = new Number(port.sld_WriteReq);

			//for perfStats.php
            this._addData("port", "band", i+1, port.sld_BandWidth);
            this._addData("port", "ios", i+1, port.sld_IOReq);
            this._addData("port", "avgla", i+1, port.sld_AverLatency);
            this._addData("port", "maxla", i+1, port.sld_MaxLantency);
            this._addData("port", "minla", i+1, port.sld_MinLantency);

			//for write and read of perfStatsForRW.php
            this._addData("port", "writeband", i+1, port.sld_WriteBandWidth);
            this._addData("port", "readband", i+1, port.sld_ReadBandWidth);
			this._addData("port", "maxwritela", i+1, port.sld_MaxWriteLatency);
			this._addData("port", "maxreadla", i+1, port.sld_MaxReadLatency);
			this._addData("port", "minwritela", i+1, port.sld_MinWriteLatency);
			this._addData("port", "minreadla", i+1, port.sld_MinReadLatency);
			this._addData("port", "averreadla", i+1, port.sld_AverReadLatency);
			this._addData("port", "averwritela", i+1, port.sld_AverWriteLatency);
			this._addData("port", "writereqs", i+1, port.sld_WriteReq);
			this._addData("port", "readreqs", i+1, port.sld_ReadReq);

			//for perfStats
            band+=port.sld_BandWidth;
            io+=port.sld_IOReq;
            aveLa+=port.sld_AverLatency;
            maxLa+=port.sld_MaxLantency;
            minLa+=port.sld_MinLantency;

			//for perfStatsForRW.php
			maxwritela+=port.sld_MaxWriteLatency;
			minwritela+=port.sld_MinWriteLatency;
			maxreadla+=port.sld_MaxReadLatency;
			minreadla+=port.sld_MinReadLatency;
			averwritela+=port.sld_AverWriteLatency;
			averreadla+=port.sld_AverReadLatency;
			writebandw+=port.sld_WriteBandWidth;
			readbandw+=port.sld_ReadBandWidth;
			writereqs+=port.sld_WriteReq;
			readreqs+=port.sld_ReadReq;

			
        }
		//for perfStats
        this._addData("port", "band", 0, band);
        this._addData("port", "ios", 0, io);
        this._addData("port", "avgla", 0, aveLa);
        this._addData("port", "maxla", 0, maxLa);
        this._addData("port", "minla", 0, minLa);

		//for perfStatsForRW.php
		this._addData("port", "writeband", 0, writebandw);
        this._addData("port", "readband", 0, readbandw);
		this._addData("port", "maxwritela", 0, maxwritela);
		this._addData("port", "maxreadla", 0, maxreadla);
		this._addData("port", "minwritela", 0, minwritela);
		this._addData("port", "minreadla", 0, minreadla);
		this._addData("port", "averreadla", 0, averreadla);
		this._addData("port", "averwritela", 0, averwritela);
		this._addData("port", "writereqs", 0, writereqs);
		this._addData("port", "readreqs", 0, readreqs);  
    };
    this._parseData = function(data)
    {
        this._parsePdData(data.pds);
        this._parseLdData(data.lds);
//        this._parsePortsData(data.ports);

    };
    this._onData = function(response)
    {   
        this._parseData(response);	
        this.isReady = true;
        this.onNewData();
	
    };
    this.loadData = function()
    {  
        dojo.xhrGet( {
            // The following URL must match that used to test the server.
            url: "perfStatsData.php", 
            handleAs: "json",
            load: dojo.hitch(this, "_onData")
        });
		
    };
    this.getData = function(){
        return this.dataList;
};            
    this.onNewData = function(){};

}
