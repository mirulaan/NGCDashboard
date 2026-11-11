using System.Text.Json.Serialization;

namespace NGCTelemetryAgent.Models;

public class TelemetryPayload
{
    [JsonPropertyName("Plant")]
    public string Plant { get; set; } = "";

    [JsonPropertyName("Line")]
    public string Line { get; set; } = "";

    [JsonPropertyName("Tier 1")]
    public string Tier1 { get; set; } = "";

    [JsonPropertyName("Tier 2")]
    public string Tier2 { get; set; } = "";

    [JsonPropertyName("IpAddress")]
    public string IpAddress { get; set; } = "";

    [JsonPropertyName("Agent")]
    public AgentInfo Agent { get; set; } = new();

    [JsonPropertyName("Backup_Status")]
    public BackupStatus? BackupStatus { get; set; }

    [JsonPropertyName("Version")]
    public Dictionary<string, string> Version { get; set; } = new();

    [JsonPropertyName("Config Model")]
    public Dictionary<string, string> ConfigModel { get; set; } = new();

    [JsonPropertyName("SSD_Status")]
    public List<SsdStatus> SsdStatus { get; set; } = new();

    [JsonPropertyName("PC Thermal")]
    public Dictionary<string, string> PcThermal { get; set; } = new();
}

public class AgentInfo
{
    [JsonPropertyName("Version")]
    public string Version { get; set; } = "1.0.0";

    [JsonPropertyName("UptimeSeconds")]
    public long UptimeSeconds { get; set; }

    [JsonPropertyName("PushIntervalSeconds")]
    public int PushIntervalSeconds { get; set; } = 300;
}

public class SsdStatus
{
    [JsonPropertyName("DiskModel")]
    public string DiskModel { get; set; } = "Drive";

    [JsonPropertyName("PhysicalDiskNumber")]
    public int PhysicalDiskNumber { get; set; }

    [JsonPropertyName("DriveLetter")]
    public string DriveLetter { get; set; } = "C:";

    [JsonPropertyName("TotalSpaceGB")]
    public double TotalSpaceGB { get; set; }

    [JsonPropertyName("RemainingSpaceGB")]
    public double RemainingSpaceGB { get; set; }

    [JsonPropertyName("TemperatureCelsius")]
    public int TemperatureCelsius { get; set; } = 35;

    [JsonPropertyName("PercentageUsed")]
    public int PercentageUsed { get; set; }

    [JsonPropertyName("AvailableSparePercent")]
    public int AvailableSparePercent { get; set; } = 100;

    [JsonPropertyName("ReallocatedSectors")]
    public int ReallocatedSectors { get; set; } = 0;

    [JsonPropertyName("PendingSectors")]
    public int PendingSectors { get; set; } = 0;

    [JsonPropertyName("UncorrectableErrors")]
    public int UncorrectableErrors { get; set; } = 0;

    [JsonPropertyName("HealthStatus")]
    public string HealthStatus { get; set; } = "PASSED";
}
